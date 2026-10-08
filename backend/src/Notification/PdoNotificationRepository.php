<?php

namespace App\Notification;

use PDO;
final class PdoNotificationRepository implements NotificationRepository
{
    private $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function preferences($householdId, $userId)
    {
        $statement = $this->pdo->prepare('SELECT task_due, inventory_expiry, expiry_days FROM jarvis_notification_preferences WHERE household_id = :household_id AND user_id = :user_id');
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId]);
        $row = $statement->fetch();
        return $row === false ? ['task_due' => true, 'inventory_expiry' => true, 'expiry_days' => 15] : ['task_due' => (bool) $row['task_due'], 'inventory_expiry' => (bool) $row['inventory_expiry'], 'expiry_days' => (int) $row['expiry_days']];
    }
    public function updatePreferences($householdId, $userId, $taskDue, $inventoryExpiry, $expiryDays = null)
    {
        $current = $this->preferences($householdId, $userId);
        $statement = $this->pdo->prepare('INSERT INTO jarvis_notification_preferences (household_id, user_id, task_due, inventory_expiry, expiry_days) VALUES (:household_id, :user_id, :task_due, :inventory_expiry, :expiry_days) ' . 'ON DUPLICATE KEY UPDATE task_due = VALUES(task_due), inventory_expiry = VALUES(inventory_expiry), expiry_days = VALUES(expiry_days), updated_at = CURRENT_TIMESTAMP(6)');
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId, 'task_due' => (isset($taskDue) ? $taskDue : $current['task_due']) ? 1 : 0, 'inventory_expiry' => (isset($inventoryExpiry) ? $inventoryExpiry : $current['inventory_expiry']) ? 1 : 0, 'expiry_days' => isset($expiryDays) ? $expiryDays : $current['expiry_days']]);
    }
    public function addGrant($householdId, $userId, $templateType)
    {
        $statement = $this->pdo->prepare('INSERT INTO jarvis_notification_grants (household_id, user_id, template_type) VALUES (:household_id, :user_id, :template_type)');
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId, 'template_type' => $templateType]);
        return (int) $this->pdo->lastInsertId();
    }
    public function cancelTaskJobs($householdId, $taskId)
    {
        $statement = $this->pdo->prepare("UPDATE jarvis_notification_jobs SET status = 'cancelled', last_error = NULL, updated_at = CURRENT_TIMESTAMP(6) " . "WHERE household_id = :household_id AND job_type = 'task_due' AND status = 'pending' " . "AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_snapshot, '\$.task_id')) AS UNSIGNED) = :task_id");
        $statement->execute(['household_id' => $householdId, 'task_id' => $taskId]);
    }
    public function upsertJob($householdId, $userId, $eventKey, $jobType, $scheduledAt, array $payload)
    {
        // A claimed payload belongs to its sender. The daily expiry event is also final after no-grant cancellation.
        $preserve = "status IN ('sending', 'sent', 'permanent_failed') OR (status = 'cancelled' AND job_type = 'inventory_expiry')";
        $statement = $this->pdo->prepare('INSERT INTO jarvis_notification_jobs (household_id, user_id, event_key, job_type, scheduled_at, payload_snapshot) ' . 'VALUES (:household_id, :user_id, :event_key, :job_type, :scheduled_at, :payload) ' . "ON DUPLICATE KEY UPDATE user_id = IF({$preserve}, user_id, VALUES(user_id)), " . "scheduled_at = IF({$preserve}, scheduled_at, VALUES(scheduled_at)), " . "payload_snapshot = IF({$preserve}, payload_snapshot, VALUES(payload_snapshot)), " . "last_error = IF({$preserve}, last_error, NULL), updated_at = IF({$preserve}, updated_at, CURRENT_TIMESTAMP(6)), " . "status = IF({$preserve}, status, 'pending')");
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId, 'event_key' => $eventKey, 'job_type' => $jobType, 'scheduled_at' => $scheduledAt, 'payload' => \App\Support\Compat::jsonEncode($payload)]);
    }
    public function expiryRecipients($fromDate)
    {
        $statement = $this->pdo->prepare('SELECT hm.household_id, hm.user_id, b.id AS batch_id, i.name, b.expires_on ' . 'FROM jarvis_household_members hm ' . 'LEFT JOIN jarvis_notification_preferences p ON p.household_id = hm.household_id AND p.user_id = hm.user_id ' . 'JOIN jarvis_inventory_batches b ON b.household_id = hm.household_id AND b.deleted_at IS NULL AND b.quantity > 0 AND b.expires_on BETWEEN :from_date AND DATE_ADD(:through_from, INTERVAL COALESCE(p.expiry_days, 15) DAY) ' . 'JOIN jarvis_ingredients i ON i.household_id = b.household_id AND i.id = b.ingredient_id ' . 'WHERE COALESCE(p.inventory_expiry, 1) = 1 ORDER BY hm.household_id, hm.user_id, b.expires_on, b.id');
        $statement->execute(['from_date' => $fromDate, 'through_from' => $fromDate]);
        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $key = $row['household_id'] . ':' . $row['user_id'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['household_id' => (int) $row['household_id'], 'user_id' => (int) $row['user_id'], 'items' => []];
            }
            $grouped[$key]['items'][] = ['batch_id' => (int) $row['batch_id'], 'name' => (string) $row['name'], 'expires_on' => (string) $row['expires_on']];
        }
        return array_values($grouped);
    }
    public function claimDueJob($nowUtc)
    {
        $statement = $this->pdo->prepare("SELECT j.id, j.household_id, j.user_id, j.event_key, j.job_type, j.scheduled_at, CAST(j.payload_snapshot AS CHAR) AS payload_snapshot, j.attempts, u.openid " . 'FROM jarvis_notification_jobs j JOIN jarvis_users u ON u.id = j.user_id ' . 'LEFT JOIN jarvis_notification_preferences p ON p.household_id = j.household_id AND p.user_id = j.user_id ' . "WHERE j.status = 'pending' AND j.attempts < 3 AND j.scheduled_at <= :now " . "AND ((j.job_type = 'task_due' AND COALESCE(p.task_due, 1) = 1) OR (j.job_type = 'inventory_expiry' AND COALESCE(p.inventory_expiry, 1) = 1)) " . 'ORDER BY j.scheduled_at, j.id LIMIT 1 FOR UPDATE');
        $statement->execute(['now' => $nowUtc]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $update = $this->pdo->prepare("UPDATE jarvis_notification_jobs SET status = 'sending', updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND status = 'pending'");
        $update->execute(['id' => $row['id']]);
        if ($update->rowCount() !== 1) {
            return null;
        }
        $payload = \App\Support\Compat::jsonDecode((string) $row['payload_snapshot']);
        return ['id' => (int) $row['id'], 'household_id' => (int) $row['household_id'], 'user_id' => (int) $row['user_id'], 'event_key' => (string) $row['event_key'], 'job_type' => (string) $row['job_type'], 'scheduled_at' => (string) $row['scheduled_at'], 'payload' => $payload, 'attempts' => (int) $row['attempts'], 'openid' => (string) $row['openid']];
    }
    public function taskJobIsCurrent(array $job)
    {
        if ($job['job_type'] !== 'task_due') {
            return true;
        }
        $payload = $job['payload'];
        $statement = $this->pdo->prepare("SELECT 1 FROM jarvis_tasks WHERE household_id = :household_id AND id = :task_id AND status = 'pending' " . 'AND assigned_to = :user_id AND due_at = :due_at LIMIT 1');
        $due = (new \DateTimeImmutable((string) $payload['due_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $statement->execute(['household_id' => $job['household_id'], 'task_id' => $payload['task_id'], 'user_id' => $job['user_id'], 'due_at' => $due]);
        return $statement->fetchColumn() !== false;
    }
    public function claimGrant($householdId, $userId, $templateType, $jobId)
    {
        $statement = $this->pdo->prepare("SELECT id FROM jarvis_notification_grants WHERE household_id = :household_id AND user_id = :user_id AND template_type = :template_type AND status = 'available' ORDER BY id LIMIT 1 FOR UPDATE");
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId, 'template_type' => $templateType]);
        $grantId = $statement->fetchColumn();
        if ($grantId === false) {
            return null;
        }
        $update = $this->pdo->prepare("UPDATE jarvis_notification_grants SET status = 'claimed', claimed_by_job_id = :job_id WHERE id = :id AND status = 'available'");
        $update->execute(['job_id' => $jobId, 'id' => $grantId]);
        return $update->rowCount() === 1 ? (int) $grantId : null;
    }
    public function cancelClaimedJob($jobId)
    {
        $statement = $this->pdo->prepare("UPDATE jarvis_notification_jobs SET status = 'cancelled', last_error = NULL, updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND status = 'sending'");
        $statement->execute(['id' => $jobId]);
    }
    public function recordSendAttempt($jobId)
    {
        $statement = $this->pdo->prepare("UPDATE jarvis_notification_jobs SET attempts = attempts + 1 WHERE id = :id AND status = 'sending' AND attempts < 3");
        $statement->execute(['id' => $jobId]);
        if ($statement->rowCount() !== 1) {
            throw new \LogicException('Only a claimed job below the retry limit can start a send attempt.');
        }
    }
    public function markSent($jobId, $grantId)
    {
        $job = $this->pdo->prepare("UPDATE jarvis_notification_jobs SET status = 'sent', sent_at = CURRENT_TIMESTAMP(6), last_error = NULL, updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND status = 'sending'");
        $job->execute(['id' => $jobId]);
        $grant = $this->pdo->prepare("UPDATE jarvis_notification_grants SET status = 'consumed', consumed_at = CURRENT_TIMESTAMP(6), claimed_by_job_id = NULL WHERE id = :id AND status = 'claimed' AND claimed_by_job_id = :job_id");
        $grant->execute(['id' => $grantId, 'job_id' => $jobId]);
    }
    public function markTransientFailure($jobId, $grantId, $attempts, $error)
    {
        $status = $attempts >= 3 ? 'permanent_failed' : 'pending';
        $job = $this->pdo->prepare("UPDATE jarvis_notification_jobs SET status = :status, last_error = :error, scheduled_at = IF(:retry_status = 'pending', DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE), scheduled_at), updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND status = 'sending'");
        $job->execute(['status' => $status, 'retry_status' => $status, 'error' => \App\Support\Text::truncate($error, 1000), 'id' => $jobId]);
        $this->restoreGrant($grantId, $jobId);
    }
    public function markPermanentFailure($jobId, $grantId, $error)
    {
        $job = $this->pdo->prepare("UPDATE jarvis_notification_jobs SET status = 'permanent_failed', last_error = :error, updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id AND status = 'sending'");
        $job->execute(['error' => \App\Support\Text::truncate($error, 1000), 'id' => $jobId]);
        $grant = $this->pdo->prepare("UPDATE jarvis_notification_grants SET status = 'consumed', consumed_at = CURRENT_TIMESTAMP(6), claimed_by_job_id = NULL WHERE id = :id AND status = 'claimed' AND claimed_by_job_id = :job_id");
        $grant->execute(['id' => $grantId, 'job_id' => $jobId]);
    }
    private function restoreGrant($grantId, $jobId)
    {
        $grant = $this->pdo->prepare("UPDATE jarvis_notification_grants SET status = 'available', claimed_by_job_id = NULL WHERE id = :id AND status = 'claimed' AND claimed_by_job_id = :job_id");
        $grant->execute(['id' => $grantId, 'job_id' => $jobId]);
    }
}
