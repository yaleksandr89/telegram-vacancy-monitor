<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Storage;

use PDO;
use RuntimeException;
use Throwable;

/**
 * RU: Сохраняет входящие сообщения и курсор обновлений Bot API в SQLite.
 * RU: Защита от дублей и продвижение курсора выполняются одной транзакцией.
 *
 * EN: Persists inbox messages and the Bot API update cursor in SQLite.
 * EN: Deduplication and cursor advancement share one transaction.
 */
final readonly class SqliteInboxRepository
{
    private const int POLLING_CURSOR_MAX_AGE = 5 * 86400;

    public function __construct(private PDO $pdo) {}

    /**
     * RU: Возвращает ID первого обновления, обработка которого ещё не сохранена.
     * EN: Returns the first update ID not yet durably processed.
     * @phpstan-impure
     */
    public function nextOffset(): int
    {
        $value = ($this->pdo->query("SELECT next_offset FROM cursor WHERE name = 'main'") ?: throw new \RuntimeException('Не выполнен запрос SQLite.'))->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    /**
     * RU: Запас до смены ID учитывает, что обновление могло провести в очереди до суток.
     * EN: The margin before IDs can reset accounts for up to one day spent in the queue.
     */
    public function pollingOffset(int $now): int
    {
        $cursor = ($this->pdo->query("SELECT next_offset, last_handled_at FROM cursor WHERE name = 'main'") ?: throw new \RuntimeException('Не выполнен запрос SQLite.'))->fetch(PDO::FETCH_ASSOC);
        if ($cursor === false) {
            return 0;
        }
        $lastHandledAt = $cursor['last_handled_at'] ?? null;
        if (!is_int($lastHandledAt) || $lastHandledAt <= 0 || $lastHandledAt > $now || $now - $lastHandledAt > self::POLLING_CURSOR_MAX_AGE) {
            return 0;
        }

        return (int) $cursor['next_offset'];
    }

    /**
     * RU: Сохраняет сообщение и курсор атомарно; null означает повтор в текущей последовательности ID.
     * EN: Saves the message and cursor atomically; null denotes a replay within the current ID sequence.
     *
     * @throws Throwable
     */
    public function applyUpdate(int $updateId, ?StoredMessage $message, ?int $handledAt = null, bool $rebase = false): ?bool
    {
        if ($updateId === PHP_INT_MAX) {
            throw new RuntimeException('Следующий polling offset выходит за пределы целого числа.');
        }

        return $this->persistUpdate($updateId, $message, $handledAt ?? time(), $rebase);
    }

    /**
     * RU: Независимые ID webhook не продвигают polling-курсор; null означает повтор самого update.
     * EN: Independent webhook IDs do not advance the polling cursor; null denotes a replayed update.
     */
    public function applyWebhookUpdate(int $updateId, ?StoredMessage $message): ?bool
    {
        return $this->persistUpdate($updateId, $message, null);
    }

    private function persistUpdate(int $updateId, ?StoredMessage $message, ?int $handledAt, bool $rebase = false): ?bool
    {
        $this->pdo->beginTransaction();

        try {
            if ($handledAt !== null) {
                $nextOffset = $updateId + 1;
                $statement = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO cursor (name, next_offset, last_handled_at)
                    VALUES ('main', :next_offset, :handled_at)
                    ON CONFLICT(name) DO UPDATE SET
                        next_offset = excluded.next_offset, last_handled_at = excluded.last_handled_at
                    WHERE :rebase OR excluded.next_offset > cursor.next_offset
                    SQL);
                $statement->execute(['next_offset' => $nextOffset, 'handled_at' => $handledAt, 'rebase' => (int) $rebase]);
                if ($statement->rowCount() !== 1) {
                    if ($rebase || $nextOffset > $this->nextOffset()) {
                        throw new RuntimeException('Не удалось сохранить курсор обработки.');
                    }
                    $this->pdo->commit();
                    return null;
                }
            } else {
                $receipt = $this->pdo->prepare('INSERT INTO received_updates (update_id) VALUES (:id) ON CONFLICT(update_id) DO NOTHING');
                $receipt->execute(['id' => $updateId]);
                if ($receipt->rowCount() === 0) {
                    $existing = $this->pdo->prepare('SELECT update_id FROM received_updates WHERE update_id = :id');
                    $existing->execute(['id' => $updateId]);
                    if ($existing->fetchColumn() === false) {
                        throw new RuntimeException('Не удалось сохранить ID webhook-обновления.');
                    }
                    $this->pdo->commit();
                    return null;
                }
            }
            $inserted = false;

            if ($message !== null) {
                // RU: Дубликаты определяются только уникальными ключами; остальные SQL-ошибки прерывают транзакцию.
                // EN: Only unique-key conflicts represent duplicates; other SQL errors must fail the transaction.
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
                if ($inserted) {
                    $job = $this->pdo->prepare('INSERT INTO analysis_jobs (message_id) VALUES (:id)');
                    $job->execute(['id' => (int) $this->pdo->lastInsertId()]);
                    if ($job->rowCount() !== 1) {
                        throw new RuntimeException('Не удалось создать задание для сохранённого сообщения.');
                    }
                }
            }

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
