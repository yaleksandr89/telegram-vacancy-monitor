<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application;

use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteInboxRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\StoredMessage;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\SendMessageRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\Update;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotApiException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;

/**
 * RU: Применяет общие правила допуска и нормализации, подтверждая сообщение только после коммита.
 * EN: Applies shared authorization and normalization, acknowledging messages only after commit.
 */
final readonly class IncomingUpdateProcessor
{
    public function __construct(
        private BotApiClient $bot,
        private SqliteInboxRepository $inbox,
        private int $allowedUserId,
    ) {}

    public function process(
        Update $update,
        bool $webhook = false,
        ?int $handledAt = null,
        bool $rebase = false,
    ): void {
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

            if ($message->isStartCommand()) {
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

        $inserted = $webhook
            ? $this->inbox->applyWebhookUpdate($update->id, $stored)
            : $this->inbox->applyUpdate($update->id, $stored, $handledAt, $rebase);
        if ($inserted === null) {
            return;
        }
        if ($stored !== null) {
            $reply = $inserted ? 'Вакансия сохранена.' : 'Эта публикация уже сохранена.';
        }

        if ($reply === null || $chatId === null) {
            return;
        }

        try {
            $this->bot->sendMessage(new SendMessageRequest($chatId, $reply));
        } catch (BotApiException | BotTransportException | UnexpectedResponseException) {
            // RU: Обновление уже сохранено; ошибка подтверждения не должна повторять приём.
            // EN: The update is durable; failed acknowledgement must not replay intake.
            error_log('Не удалось отправить подтверждение сохранённого обновления.');
        }
    }
}
