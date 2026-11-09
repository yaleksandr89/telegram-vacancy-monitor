<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Storage;

use PDO;

/**
 * RU: Инициализирует существующую схему SQLite отдельно от операций с сообщениями.
 * EN: Initializes the existing SQLite inbox schema separately from message operations.
 */
final readonly class SqliteSchema
{
    public function __construct(private PDO $pdo) {}

    /**
     * RU: Повторяемая инициализация без удаления или миграции пользовательских данных.
     * EN: Idempotent bootstrap; does not drop or migrate existing user data.
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
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS analysis_jobs (
                message_id INTEGER PRIMARY KEY REFERENCES messages(id),
                analysis_status TEXT NOT NULL DEFAULT 'pending'
                    CHECK (analysis_status IN ('pending', 'in_progress', 'completed', 'failed')),
                analysis_attempts INTEGER NOT NULL DEFAULT 0 CHECK (analysis_attempts >= 0),
                analysis_next_at INTEGER NOT NULL DEFAULT 0,
                analysis_error TEXT,
                profile_hash TEXT,
                result_json TEXT,
                verdict TEXT CHECK (verdict IN ('apply', 'borderline', 'skip', 'technical_failure')),
                notification TEXT,
                delivery_status TEXT NOT NULL DEFAULT 'waiting'
                    CHECK (delivery_status IN ('waiting', 'pending', 'in_progress', 'delivered', 'failed')),
                delivery_attempts INTEGER NOT NULL DEFAULT 0 CHECK (delivery_attempts >= 0),
                delivery_next_at INTEGER NOT NULL DEFAULT 0,
                delivery_error TEXT,
                CHECK (analysis_status NOT IN ('completed', 'failed') OR notification IS NOT NULL)
            )
            SQL);
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS analysis_due ON analysis_jobs (analysis_status, analysis_next_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS delivery_due ON analysis_jobs (delivery_status, delivery_next_at)');
    }
}
