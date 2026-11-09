<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Storage;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ProcessingStage;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Verdict;

/**
 * RU: Сохраняет в SQLite проверяемые переходы состояний заданий и независимые сроки повторных попыток.
 * EN: Persists guarded job transitions and independent retry schedules in SQLite.
 */
final readonly class SqliteAnalysisRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * RU: Возвращает первое задание выбранного этапа, срок обработки которого наступил.
     * EN: Returns the first due job for the selected stage.
     *
     * @throws PDOException
     */
    public function due(ProcessingStage $stage, int $now): ?array
    {
        $stageName = $stage->value;
        $statement = $this->pdo->prepare("SELECT j.*, m.body, m.origin_type, m.origin_chat_id, m.origin_message_id,
            m.recipient_chat_id, m.recipient_message_id
            FROM analysis_jobs j JOIN messages m ON m.id = j.message_id
            WHERE j.{$stageName}_status = 'pending' AND j.{$stageName}_next_at <= :now
            ORDER BY j.{$stageName}_next_at, j.message_id LIMIT 1");
        $statement->execute(['now' => $now]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * RU: Возвращает ближайшее время ожидающей обработки среди обоих этапов.
     * EN: Returns the earliest pending time across both stages.
     *
     * @throws PDOException
     */
    public function nextDueAt(): ?int
    {
        $value = $this->pdo->query("SELECT MIN(due_at) FROM (
            SELECT analysis_next_at AS due_at FROM analysis_jobs WHERE analysis_status = 'pending'
            UNION ALL SELECT delivery_next_at FROM analysis_jobs WHERE delivery_status = 'pending'
        )")->fetchColumn();

        return $value === null || $value === false ? null : (int) $value;
    }

    /**
     * RU: Возвращает попытки, прерванные во время выполнения.
     * EN: Returns attempts interrupted while in progress.
     *
     * @throws PDOException
     */
    public function interrupted(ProcessingStage $stage): array
    {
        $stageName = $stage->value;
        return $this->pdo->query("SELECT j.*, m.body, m.origin_type, m.origin_chat_id, m.origin_message_id, m.recipient_message_id FROM analysis_jobs j
            JOIN messages m ON m.id = j.message_id WHERE {$stageName}_status = 'in_progress'")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * RU: Начинает готовое задание и учитывает попытку; profile_hash хранит общую версию профиля и инструкций.
     * EN: Claims a due job and counts its attempt; profile_hash stores the composite profile/instruction version.
     *
     * @throws PDOException
     * @throws RuntimeException
     */
    public function begin(ProcessingStage $stage, int $id, int $now, ?string $profileHash = null, bool $countAttempt = true): void
    {
        $stageName = $stage->value;
        $hashSql = $stage === ProcessingStage::Analysis ? ', profile_hash = :hash' : '';
        $statement = $this->pdo->prepare("UPDATE analysis_jobs SET {$stageName}_status = 'in_progress',
            {$stageName}_attempts = {$stageName}_attempts + :increment {$hashSql}
            WHERE message_id = :id AND {$stageName}_status = 'pending' AND {$stageName}_next_at <= :now");
        $parameters = ['id' => $id, 'now' => $now, 'increment' => $countAttempt ? 1 : 0];
        if ($stage === ProcessingStage::Analysis) {
            $parameters['hash'] = $profileHash;
        }
        $statement->execute($parameters);
        $this->assertChanged($statement);
    }

    /**
     * RU: Сохраняет оценку и уведомление, затем разрешает доставку.
     * EN: Saves the evaluation and notification before making delivery eligible.
     *
     * @throws PDOException
     * @throws RuntimeException
     */
    public function complete(int $id, Verdict $verdict, ?string $resultJson, string $notification): void
    {
        $statement = $this->pdo->prepare("UPDATE analysis_jobs SET analysis_status = 'completed',
            verdict = :verdict, result_json = :result, notification = :notification,
            delivery_status = 'pending', analysis_error = NULL WHERE message_id = :id AND analysis_status = 'in_progress'");
        $statement->execute(['id' => $id, 'verdict' => $verdict->value, 'result' => $resultJson, 'notification' => $notification]);
        $this->assertChanged($statement);
    }

    /**
     * RU: Назначает повторную попытку или сохраняет окончательный сбой без удаления исходного сообщения.
     * EN: Schedules another attempt or records terminal failure without deleting the source.
     *
     * @throws PDOException
     * @throws RuntimeException
     */
    public function fail(ProcessingStage $stage, int $id, string $category, bool $terminal, int $nextAt, ?string $notification = null): void
    {
        $stageName = $stage->value;
        $extra = $stage === ProcessingStage::Analysis && $terminal
            ? ", verdict = 'technical_failure', notification = :notification, delivery_status = 'pending'" : '';
        $statement = $this->pdo->prepare("UPDATE analysis_jobs SET {$stageName}_status = :status,
            {$stageName}_error = :error, {$stageName}_next_at = :next {$extra}
            WHERE message_id = :id AND {$stageName}_status = 'in_progress'");
        $parameters = ['id' => $id, 'status' => $terminal ? 'failed' : 'pending', 'error' => $category, 'next' => $nextAt];
        if ($extra !== '') {
            $parameters['notification'] = $notification;
        }
        $statement->execute($parameters);
        $this->assertChanged($statement);
    }

    /**
     * RU: Отмечает выполняемую доставку как завершённую.
     * EN: Marks an active delivery as completed.
     *
     * @throws PDOException
     * @throws RuntimeException
     */
    public function delivered(int $id): void
    {
        $statement = $this->pdo->prepare("UPDATE analysis_jobs SET delivery_status = 'delivered', delivery_error = NULL
            WHERE message_id = :id AND delivery_status = 'in_progress'");
        $statement->execute(['id' => $id]);
        $this->assertChanged($statement);
    }

    private function assertChanged(PDOStatement $statement): void
    {
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Нарушен ожидаемый переход состояния задания.');
        }
    }
}
