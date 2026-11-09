<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application;

use InvalidArgumentException;
use Yaleksandr\TelegramVacancyMonitor\Analysis\CandidateProfile;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ConservativePrefilter;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Exception\AiException;
use Yaleksandr\TelegramVacancyMonitor\Analysis\OpenAiCompatibleEvaluator;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ProcessingStage;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Verdict;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteAnalysisRepository;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\SendMessageRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotApiException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;

/**
 * RU: Сохраняет оценку до доставки и повторяет каждый этап независимо.
 * EN: Persists evaluation before delivery and retries each stage independently.
 */
final readonly class VacancyAnalysisPipeline
{
    public function __construct(
        private SqliteAnalysisRepository $jobs,
        private CandidateProfile $profile,
        private ConservativePrefilter $prefilter,
        private OpenAiCompatibleEvaluator $evaluator,
        private VacancyResultFormatter $formatter,
        private BotApiClient $bot,
        private int $maxAttempts = 3,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 5) {
            throw new InvalidArgumentException('Число попыток должно быть от 1 до 5.');
        }
    }

    /**
     * RU: Восстанавливает прерванные попытки один раз под блокировкой процесса; ошибки хранилища передаются вызывающему коду.
     * EN: Recovers interrupted attempts once under the process lock; storage errors propagate.
     */
    public function recover(int $now): void
    {
        // RU: Выполнять один раз под блокировкой процесса; прерванные HTTP-попытки уже учтены.
        // EN: Run once under the process lock; interrupted HTTP attempts have already been counted.
        foreach (ProcessingStage::cases() as $stage) {
            foreach ($this->jobs->interrupted($stage) as $job) {
                $this->failure($stage, $job, 'interrupted', true, $now);
            }
        }
    }

    public function nextDueAt(): ?int
    {
        return $this->jobs->nextDueAt();
    }

    /**
     * RU: Обрабатывает не более одной оценки и одной доставки, сохраняя результат до отправки.
     * EN: Processes at most one evaluation and one delivery, saving results before sending.
     */
    public function processPending(int $now): bool
    {
        $started = hrtime(true);
        $analysis = $this->processAnalysis($now);
        $delivery = $this->processDelivery($now + (int) ((hrtime(true) - $started) / 1e9));

        return $analysis || $delivery;
    }

    private function processAnalysis(int $now): bool
    {
        $job = $this->jobs->due(ProcessingStage::Analysis, $now);
        if ($job !== null) {
            $reason = $this->prefilter->reject($job['body']);
            $id = (int) $job['message_id'];
            $exhausted = (int) $job['analysis_attempts'] >= $this->maxAttempts;
            $countAttempt = $reason === null && !$exhausted;
            $this->jobs->begin(ProcessingStage::Analysis, $id, $now, $this->evaluator->fingerprint($this->profile), $countAttempt);
            if ($exhausted) {
                $this->failure(ProcessingStage::Analysis, $job, 'attempt_limit', false, $now);
                return true;
            }
            if ($countAttempt) {
                ++$job['analysis_attempts'];
            }
            $started = hrtime(true);
            try {
                $result = $reason === null ? $this->evaluator->evaluate($job['body'], $this->profile) : null;
                $verifiedOriginIds = $this->formatter->verifiedSource($job) !== null;
                $resultJson = $result?->toJson($verifiedOriginIds);
                $notification = $this->formatter->format(
                    $result, (int) $job['recipient_message_id'], $reason, $this->formatter->sourceDescription($job),
                );
            } catch (AiException $exception) {
                $this->failure(ProcessingStage::Analysis, $job, $exception->category, $exception->retryable, $now + (int) ((hrtime(true) - $started) / 1e9));
                return true;
            }
            $this->jobs->complete($id, $result?->verdict ?? Verdict::Skip, $resultJson, $notification);
            return true;
        }

        return false;
    }

    private function processDelivery(int $now): bool
    {
        $job = $this->jobs->due(ProcessingStage::Delivery, $now);
        $exhausted = $job !== null && (int) $job['delivery_attempts'] >= $this->maxAttempts;
        if ($job !== null) {
            $this->jobs->begin(ProcessingStage::Delivery, (int) $job['message_id'], $now, countAttempt: !$exhausted);
            if ($exhausted) {
                $this->failure(ProcessingStage::Delivery, $job, 'attempt_limit', false, $now);
                return true;
            }
            ++$job['delivery_attempts'];
            $sent = false;
            $started = hrtime(true);
            try {
                $this->bot->sendMessage(new SendMessageRequest((int) $job['recipient_chat_id'], $job['notification']));
                $sent = true;
            } catch (BotApiException $exception) {
                $code = $exception->apiCode ?? $exception->httpStatus;
                $this->failure(ProcessingStage::Delivery, $job, 'telegram_' . $code, $code === 429 || ($code >= 500 && $code <= 599), $now + (int) ((hrtime(true) - $started) / 1e9));
            } catch (BotTransportException | UnexpectedResponseException) {
                $this->failure(ProcessingStage::Delivery, $job, 'telegram_transport_or_response', true, $now + (int) ((hrtime(true) - $started) / 1e9));
            }
            if ($sent) {
                $this->jobs->delivered((int) $job['message_id']);
            }
            return true;
        }

        return false;
    }

    private function failure(ProcessingStage $stage, array $job, string $category, bool $retryable, int $now): void
    {
        $attempts = (int) $job[$stage->value . '_attempts'];
        $terminal = !$retryable || $attempts >= $this->maxAttempts;
        $this->jobs->fail(
            $stage, (int) $job['message_id'], $category, $terminal,
            $now + min(300, 15 * (2 ** min($attempts, 4))),
            $stage === ProcessingStage::Analysis && $terminal
                ? $this->formatter->format(null, (int) $job['recipient_message_id'], source: $this->formatter->sourceDescription($job)) : null,
        );
    }
}
