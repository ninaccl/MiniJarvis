<?php

declare(strict_types=1);

namespace App\Notification;

use PDO;

final class PdoNotificationRepository implements NotificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function preferences(int $householdId, int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT task_due, inventory_expiry FROM notification_preferences WHERE household_id = :household_id AND user_id = :user_id');
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId]);
        $row = $statement->fetch();
        return $row === false ? ['task_due' => true, 'inventory_expiry' => true] : [
            'task_due' => (bool) $row['task_due'], 'inventory_expiry' => (bool) $row['inventory_expiry'],
        ];
    }

    public function updatePreferences(int $householdId, int $userId, ?bool $taskDue, ?bool $inventoryExpiry): void
    {
        $current = $this->preferences($householdId, $userId);
        $statement = $this->pdo->prepare(
            'INSERT INTO notification_preferences (household_id, user_id, task_due, inventory_expiry) VALUES (:household_id, :user_id, :task_due, :inventory_expiry) '
            . 'ON DUPLICATE KEY UPDATE task_due = VALUES(task_due), inventory_expiry = VALUES(inventory_expiry), updated_at = CURRENT_TIMESTAMP(6)'
        );
        $statement->execute([
            'household_id' => $householdId, 'user_id' => $userId,
            'task_due' => ($taskDue ?? $current['task_due']) ? 1 : 0,
            'inventory_expiry' => ($inventoryExpiry ?? $current['inventory_expiry']) ? 1 : 0,
        ]);
    }

    public function addGrant(int $householdId, int $userId, string $templateType): int
    {
        $statement = $this->pdo->prepare('INSERT INTO notification_grants (household_id, user_id, template_type) VALUES (:household_id, :user_id, :template_type)');
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId, 'template_type' => $templateType]);
        return (int) $this->pdo->lastInsertId();
    }

    public function cancelTaskJobs(int $householdId, int $taskId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE notification_jobs SET status = 'cancelled', last_error = NULL, updated_at = CURRENT_TIMESTAMP(6) "
            . "WHERE household_id = :household_id AND job_type = 'task_due' AND status = 'pending' "
            . "AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_snapshot, '$.task_id')) AS UNSIGNED) = :task_id"
        );
        $statement->execute(['household_id' => $householdId, 'task_id' => $taskId]);
    }

    public function upsertJob(int $householdId, int $userId, string $eventKey, string $jobType, string $scheduledAt, array $payload): void
    {
        // A claimed payload belongs to its sender. The daily expiry event is also final after no-grant cancellation.
        $preserve = "status IN ('sending', 'sent', 'permanent_failed') OR (status = 'cancelled' AND job_type = 'inventory_expiry')";
        $statement = $this->pdo->prepare(
            'INSERT INTO notification_jobs (household_id, user_id, event_key, job_type, scheduled_at, payload_snapshot) '
            . 'VALUES (:household_id, :user_id, :event_key, :job_type, :scheduled_at, :payload) '
            . "ON DUPLICATE KEY UPDATE user_id = IF($preserve, user_id, VALUES(user_id)), "
            . "scheduled_at = IF($preserve, scheduled_at, VALUES(scheduled_at)), "
            . "payload_snapshot = IF($preserve, payload_snapshot, VALUES(payload_snapshot)), "
            . "last_error = IF($preserve, last_error, NULL), updated_at = IF($preserve, updated_at, CURRENT_TIMESTAMP(6)), "
            . "status = IF($preserve, status, 'pending')"
        );
        $statement->execute([
            'household_id' => $householdId, 'user_id' => $userId, 'event_key' => $eventKey,
            'job_type' => $jobType, 'scheduled_at' => $scheduledAt,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    public function expiryRecipients(string $fromDate, string $throughDate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT hm.household_id, hm.user_id, b.id AS batch_id, i.name, b.expires_on '
            . 'FROM household_members hm '
            . 'LEFT JOIN notification_preferences p ON p.household_id = hm.household_id AND p.user_id = hm.user_id '
            . 'JOIN inventory_batches b ON b.household_id = hm.household_id AND b.quantity > 0 AND b.expires_on BETWEEN :from_date AND :through_date '
            . 'JOIN ingredients i ON i.household_id = b.household_id AND i.id = b.ingredient_id '
            . 'WHERE COALESCE(p.inventory_expiry, 1) = 1 ORDER BY hm.household_id, hm.user_id, b.expires_on, b.id'
        );
        $statement->execute(['from_date' => $fromDate, 'through_date' => $throughDate]);
        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $key = $row['household_id'] . ':' . $row['user_id'];
            if (!isset($grouped[$key])) $grouped[$key] = ['household_id' => (int) $row['household_id'], 'user_id' => (int) $row['user_id'], 'items' => []];
            $grouped[$key]['items'][] = ['batch_id' => (int) $row['batch_id'], 'name' => (string) $row['name'], 'expires_on' => (string) $row['expires_on']];
        }
        return array_values($grouped);
    }

    public function claimDueJob(string $nowUtc): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT j.id, j.household_id, j.user_id, j.event_key, j.job_type, j.scheduled_at, j.payload_snapshot, j.attempts, u.openid "
            . 'FROM notification_jobs j JOIN users u ON u.id = j.user_id '
            . 'LEFT JOIN notification_preferences p ON p.household_id = j.household_id AND p.user_id = j.user_id '
            . "WHERE j.status = 'pending' AND j.attempts < 3 AND j.scheduled_at <= :now "
            . "AND ((j.job_type = 'task_due' AND COALESCE(p.task_due, 1) = 1) OR (j.job_type = 'inventory_expiry' AND COALESCE(p.inventory_expiry, 1) = 1)) "
            . 'ORDER BY j.scheduled_at, j.id LIMIT 1 FOR UPDATE SKIP LOCKED'
        );
        $statement->execute(['now' => $nowUtc]);
        $row = $statement->fetch();
        if ($row === false) return null;
        $update = $this->pdo->prepare("UPDATE notification_jobs SET status = 'sending', updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND status = 'pending'");
        $update->execute(['id' => $row['id']]);
        if ($update->rowCount() !== 1) return null;
        $payload = json_decode((string) $row['payload_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        return [
            'id' => (int) $row['id'], 'household_id' => (int) $row['household_id'], 'user_id' => (int) $row['user_id'],
            'event_key' => (string) $row['event_key'], 'job_type' => (string) $row['job_type'], 'scheduled_at' => (string) $row['scheduled_at'],
            'payload' => $payload, 'attempts' => (int) $row['attempts'], 'openid' => (string) $row['openid'],
        ];
    }

    public function taskJobIsCurrent(array $job): bool
    {
        if ($job['job_type'] !== 'task_due') return true;
        $payload = $job['payload'];
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM tasks WHERE household_id = :household_id AND id = :task_id AND status = 'pending' "
            . 'AND assigned_to = :user_id AND due_at = :due_at LIMIT 1'
        );
        $due = (new \DateTimeImmutable((string) $payload['due_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $statement->execute(['household_id' => $job['household_id'], 'task_id' => $payload['task_id'], 'user_id' => $job['user_id'], 'due_at' => $due]);
        return $statement->fetchColumn() !== false;
    }

    public function claimGrant(int $householdId, int $userId, string $templateType, int $jobId): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM notification_grants WHERE household_id = :household_id AND user_id = :user_id AND template_type = :template_type AND status = 'available' ORDER BY id LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId, 'template_type' => $templateType]);
        $grantId = $statement->fetchColumn();
        if ($grantId === false) return null;
        $update = $this->pdo->prepare("UPDATE notification_grants SET status = 'claimed', claimed_by_job_id = :job_id WHERE id = :id AND status = 'available'");
        $update->execute(['job_id' => $jobId, 'id' => $grantId]);
        return $update->rowCount() === 1 ? (int) $grantId : null;
    }

    public function cancelClaimedJob(int $jobId): void
    {
        $statement = $this->pdo->prepare("UPDATE notification_jobs SET status = 'cancelled', last_error = NULL, updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND status = 'sending'");
        $statement->execute(['id' => $jobId]);
    }

    public function recordSendAttempt(int $jobId): void
    {
        $statement = $this->pdo->prepare("UPDATE notification_jobs SET attempts = attempts + 1 WHERE id = :id AND status = 'sending' AND attempts < 3");
        $statement->execute(['id' => $jobId]);
        if ($statement->rowCount() !== 1) throw new \LogicException('Only a claimed job below the retry limit can start a send attempt.');
    }

    public function markSent(int $jobId, int $grantId): void
    {
        $job = $this->pdo->prepare("UPDATE notification_jobs SET status = 'sent', sent_at = CURRENT_TIMESTAMP(6), last_error = NULL, updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND status = 'sending'");
        $job->execute(['id' => $jobId]);
        $grant = $this->pdo->prepare("UPDATE notification_grants SET status = 'consumed', consumed_at = CURRENT_TIMESTAMP(6), claimed_by_job_id = NULL WHERE id = :id AND status = 'claimed' AND claimed_by_job_id = :job_id");
        $grant->execute(['id' => $grantId, 'job_id' => $jobId]);
    }

    public function markTransientFailure(int $jobId, int $grantId, int $attempts, string $error): void
    {
        $status = $attempts >= 3 ? 'permanent_failed' : 'pending';
        $job = $this->pdo->prepare("UPDATE notification_jobs SET status = :status, last_error = :error, scheduled_at = IF(:retry_status = 'pending', DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE), scheduled_at), updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND status = 'sending'");
        $job->execute(['status' => $status, 'retry_status' => $status, 'error' => mb_substr($error, 0, 1000), 'id' => $jobId]);
        $this->restoreGrant($grantId, $jobId);
    }

    public function markPermanentFailure(int $jobId, int $grantId, string $error): void
    {
        $job = $this->pdo->prepare("UPDATE notification_jobs SET status = 'permanent_failed', last_error = :error, updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND status = 'sending'");
        $job->execute(['error' => mb_substr($error, 0, 1000), 'id' => $jobId]);
        $grant = $this->pdo->prepare("UPDATE notification_grants SET status = 'consumed', consumed_at = CURRENT_TIMESTAMP(6), claimed_by_job_id = NULL WHERE id = :id AND status = 'claimed' AND claimed_by_job_id = :job_id");
        $grant->execute(['id' => $grantId, 'job_id' => $jobId]);
    }

    private function restoreGrant(int $grantId, int $jobId): void
    {
        $grant = $this->pdo->prepare("UPDATE notification_grants SET status = 'available', claimed_by_job_id = NULL WHERE id = :id AND status = 'claimed' AND claimed_by_job_id = :job_id");
        $grant->execute(['id' => $grantId, 'job_id' => $jobId]);
    }
}
