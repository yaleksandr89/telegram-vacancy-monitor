<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application;

use Closure;
use InvalidArgumentException;
use Throwable;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteInboxRepository;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\GetUpdatesRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\Update;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;

/**
 * RU: Принимает разрешённые личные сообщения и сохраняет их до отправки подтверждения.
 * EN: Receives authorized private messages and stores them before acknowledging receipt.
 */
final readonly class BotRunner
{
    /**
     * @param BotApiClient $bot
     * @param SqliteInboxRepository $inbox
     * @param int $allowedUserId
     * @param VacancyAnalysisPipeline|null $pipeline
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private BotApiClient $bot,
        private SqliteInboxRepository $inbox,
        private int $allowedUserId,
        private ?VacancyAnalysisPipeline $pipeline = null,
        private ?Closure $clock = null,
    ) {}

    /**
     * RU: Выполняет long polling до остановки оператором или возникновения ошибки.
     * EN: Runs the long-polling worker until stopped by the operator or an error.
     *
     * @throws Throwable
     */
    public function run(): never
    {
        while (true) {
            $this->runIteration();
        }
    }

    /**
     * RU: Обрабатывает ожидающие задания и принимает обновления; ошибки хранилища передаются вызывающему коду.
     * EN: Processes pending work and receives updates; storage errors propagate to the caller.
     *
     * @throws Throwable
     */
    public function runIteration(): void
    {
        // RU: Обрабатываем несколько ожидающих заданий, затем снова проверяем новые сообщения.
        // EN: Process a few pending jobs, then check for new messages again.
        $started = hrtime(true);
        for ($batchIndex = 0; $batchIndex < 3; ++$batchIndex) {
            if (!($this->pipeline?->processPending(time()) ?? false) || hrtime(true) - $started >= 2_000_000_000) {
                break;
            }
        }
        $nextAt = $this->pipeline?->nextDueAt();
        $timeout = $nextAt === null ? 25 : max(1, min(25, $nextAt - time()));
        $clock = $this->clock ?? time(...);
        $offset = $this->inbox->pollingOffset($clock());
        $updates = $this->bot->getUpdates(new GetUpdatesRequest($offset, $timeout));

        // RU: Проверяем весь ответ до продвижения курсора, чтобы повторный запуск не подтвердил пропуск.
        // EN: Check the complete response before advancing the cursor so a restart cannot acknowledge a skipped update.
        $previousId = $offset;
        foreach ($updates as $update) {
            if ($update->id < $previousId) {
                throw new UnexpectedResponseException(
                    'Bot API вернул update_id ниже запрошенного offset или предыдущего ID в пакете. Курсор не продвинут.',
                );
            }
            $previousId = $update->id;
        }

        $processor = new IncomingUpdateProcessor($this->bot, $this->inbox, $this->allowedUserId);
        $rebase = $offset === 0;
        foreach ($updates as $update) {
            $processor->process($update, handledAt: $clock(), rebase: $rebase);
            $rebase = false;
        }
    }

    /**
     * RU: Игнорирует посторонних отправителей и фиксирует обновление до ответа.
     * EN: Ignores unauthorized senders and commits each update before replying.
     *
     * @throws Throwable
     */
    public function processUpdate(Update $update): void
    {
        new IncomingUpdateProcessor($this->bot, $this->inbox, $this->allowedUserId)->process($update);
    }

    /**
     * RU: Выполняет конечный cron batch под общей блокировкой; занятый воркер пропускает tick.
     * RU: Лимит времени ограничивает начало следующих заданий, а не текущий HTTP-запрос.
     * EN: Runs a finite cron batch under the shared lock; a busy worker skips the tick.
     * EN: The time budget limits starting subsequent jobs, not the active HTTP request.
     *
     * @param resource $lock
     */
    public function workOnce(mixed $lock, int $maxBatches = 10, float $maxSeconds = 20.0): bool
    {
        if (!is_resource($lock) || $this->pipeline === null || $maxBatches < 1 || $maxSeconds <= 0 || !is_finite($maxSeconds)) {
            throw new InvalidArgumentException('Для воркера нужны блокировка, pipeline и положительные лимиты.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return false;
        }

        try {
            $started = hrtime(true);
            $this->pipeline->recover(time());
            for ($batch = 0; $batch < $maxBatches && (hrtime(true) - $started) / 1e9 < $maxSeconds; ++$batch) {
                if (!$this->pipeline->processPending(time())) {
                    break;
                }
            }
        } finally {
            flock($lock, LOCK_UN);
        }

        return true;
    }
}
