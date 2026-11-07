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
 * EN: Receives authorized private messages and stores them before acknowledging receipt.
 * RU: Принимает разрешённые личные сообщения и сохраняет их до отправки подтверждения.
 */
final readonly class BotRunner
{
    public function __construct(
        private BotApiClient $bot,
        private SqliteInboxRepository $inbox,
        private int $allowedUserId,
    ) {}

    /**
     * EN: Runs the long-polling worker until stopped by the operator or an error.
     * RU: Выполняет long polling до остановки оператором или возникновения ошибки.
     *
     * @throws BotApiException
     * @throws BotTransportException
     * @throws UnexpectedResponseException
     */
    public function run(): never
    {
        while (true) {
            $request = new GetUpdatesRequest($this->inbox->nextOffset(), 25);

            foreach ($this->bot->getUpdates($request) as $update) {
                $this->processUpdate($update);
            }
        }
    }

    /**
     * EN: Ignores unauthorized senders and commits each update before replying.
     * RU: Игнорирует посторонних отправителей и фиксирует обновление до ответа.
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
            } elseif ($body !== null && trim($body) !== '') {
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
            // EN: The update is durable, so failed delivery must not replay the vacancy.
            // RU: Обновление уже сохранено; ошибка отправки не должна повторно обрабатывать вакансию.
            fwrite(STDERR, 'Не удалось отправить подтверждение: ' . $exception->getMessage() . PHP_EOL);
        }
    }
}
