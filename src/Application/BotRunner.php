<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application;

use Throwable;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteInboxRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\StoredMessage;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\GetUpdatesRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\SendMessageRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\Update;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotApiException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;

/**
 * RU: Принимает разрешённые личные сообщения и сохраняет их до отправки подтверждения.
 * EN: Receives authorized private messages and stores them before acknowledging receipt.
 */
final readonly class BotRunner
{
    public function __construct(
        private BotApiClient $bot,
        private SqliteInboxRepository $inbox,
        private int $allowedUserId,
        private ?VacancyAnalysisPipeline $pipeline = null,
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
        $request = new GetUpdatesRequest($this->inbox->nextOffset(), $timeout);

        foreach ($this->bot->getUpdates($request) as $update) {
            $this->processUpdate($update);
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
        if ($update->id < $this->inbox->nextOffset()) {
            return;
        }

        $message = $update->message;
        $stored = null;
        $reply = null;
        $chatId = null;

        if (
            $message !== null
            && $message->chatType === 'private'
            && $message->senderId === $this->allowedUserId
        ) {
            $chatId = $message->chatId;
            $body = $message->body;

            if ($body !== null && str_starts_with($body, '/start')) {
                $reply = 'Перешли сюда вакансию. Я сохраню текст и отвечу подтверждением.';
            } elseif ($body !== null && mb_trim($body, encoding: 'UTF-8') !== '') {
                $stored = new StoredMessage(
                    recipientChatId: $message->chatId,
                    recipientMessageId: $message->messageId,
                    originType: $message->originType,
                    originChatId: $message->originChatId,
                    originMessageId: $message->originMessageId,
                    body: $body,
                );
            } else {
                $reply = 'Пришли текст вакансии или перешли публикацию с текстом.';
            }
        }

        $inserted = $this->inbox->applyUpdate($update->id, $stored);

        if ($stored !== null) {
            $reply = $inserted ? 'Вакансия сохранена.' : 'Эта публикация уже сохранена.';
        }

        if ($reply === null || $chatId === null) {
            return;
        }

        try {
            $this->bot->sendMessage(new SendMessageRequest($chatId, $reply));
        } catch (BotApiException | BotTransportException | UnexpectedResponseException $exception) {
            // RU: Обновление уже сохранено; ошибка отправки не должна повторно обрабатывать вакансию.
            // EN: The update is durable, so failed delivery must not replay the vacancy.
            fwrite(STDERR, 'Не удалось отправить подтверждение: ' . $exception->getMessage() . PHP_EOL);
        }
    }
}
