<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Storage;

use PDO;
use Throwable;

/**
 * EN: Persists inbox messages and the Bot API update cursor in SQLite.
 * RU: Сохраняет входящие сообщения и курсор обновлений Bot API в SQLite.
 *
 * EN: Deduplication and cursor advancement share one transaction.
 * RU: Защита от дублей и продвижение курсора выполняются одной транзакцией.
 */
final readonly class SqliteInboxRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * EN: Returns the first update ID not yet durably processed.
     * RU: Возвращает ID первого обновления, обработка которого ещё не сохранена.
     */
    public function nextOffset(): int
    {
        $value = $this->pdo->query("SELECT next_offset FROM cursor WHERE name = 'main'")->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    /**
     * EN: Saves an optional message and commits its update ID atomically.
     * RU: Атомарно сохраняет необязательное сообщение и ID обработанного обновления.
     *
     * @throws Throwable
     */
    public function applyUpdate(int $updateId, ?StoredMessage $message): bool
    {
        $this->pdo->beginTransaction();

        try {
            $inserted = false;

            if ($message !== null) {
                // EN: Only unique-key conflicts represent duplicates; other SQL errors must fail the transaction.
                // RU: Дубликаты определяются только уникальными ключами; остальные SQL-ошибки прерывают транзакцию.
                $statement = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO messages (
                        recipient_chat_id, recipient_message_id, origin_type,
                        origin_chat_id, origin_message_id, body
                    ) VALUES (
                        :recipient_chat_id, :recipient_message_id, :origin_type,
                        :origin_chat_id, :origin_message_id, :body
                    ) ON CONFLICT DO NOTHING
                    SQL);
                $statement->execute([
                    'recipient_chat_id' => $message->recipientChatId,
                    'recipient_message_id' => $message->recipientMessageId,
                    'origin_type' => $message->originType,
                    'origin_chat_id' => $message->originChatId,
                    'origin_message_id' => $message->originMessageId,
                    'body' => $message->body,
                ]);
                $inserted = $statement->rowCount() === 1;
            }

            // EN: Never advance the cursor independently of the stored message.
            // RU: Нельзя продвигать курсор отдельно от сохранения сообщения.
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO cursor (name, next_offset)
                VALUES ('main', :next_offset)
                ON CONFLICT(name) DO UPDATE SET next_offset = MAX(next_offset, excluded.next_offset)
                SQL);
            $statement->execute(['next_offset' => $updateId + 1]);
            $this->pdo->commit();

            return $inserted;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
