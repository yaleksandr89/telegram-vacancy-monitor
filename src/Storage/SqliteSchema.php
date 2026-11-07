<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Storage;

use PDO;

/**
 * EN: Initializes the existing SQLite inbox schema separately from message operations.
 * RU: Инициализирует существующую схему SQLite отдельно от операций с сообщениями.
 */
final readonly class SqliteSchema
{
    public function __construct(private PDO $pdo) {}

    /**
     * EN: Idempotent bootstrap; does not drop or migrate existing user data.
     * RU: Повторяемая инициализация без удаления или миграции пользовательских данных.
     */
    public function ensure(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY,
                recipient_chat_id INTEGER NOT NULL,
                recipient_message_id INTEGER NOT NULL,
                origin_type TEXT NOT NULL,
                origin_chat_id INTEGER,
                origin_message_id INTEGER,
                body TEXT NOT NULL,
                saved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (recipient_chat_id, recipient_message_id)
            )
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS messages_origin_unique
                ON messages (origin_chat_id, origin_message_id)
                WHERE origin_chat_id IS NOT NULL AND origin_message_id IS NOT NULL
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cursor (
                name TEXT PRIMARY KEY,
                next_offset INTEGER NOT NULL
            )
            SQL);
    }
}
