<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Notification\NotificationRepository;

final class InMemoryNotificationRepository implements NotificationRepository
{
    public array $preferences = [];
    public array $grants = [];
    public array $jobs = [];
    public array $expiry = [];
    public bool $current = true;
    private int $nextGrant = 1;
    private int $nextJob = 1;

    public function preferences(int $householdId, int $userId): array { return $this->preferences["$householdId:$userId"] ?? ['task_due' => true, 'inventory_expiry' => true]; }
    public function updatePreferences(int $householdId, int $userId, ?bool $taskDue, ?bool $inventoryExpiry): void
    {
        $current = $this->preferences($householdId, $userId);
        $this->preferences["$householdId:$userId"] = ['task_due' => $taskDue ?? $current['task_due'], 'inventory_expiry' => $inventoryExpiry ?? $current['inventory_expiry']];
    }
    public function addGrant(int $householdId, int $userId, string $templateType): int
    {
        $id = $this->nextGrant++;
        $this->grants[$id] = ['id' => $id, 'household_id' => $householdId, 'user_id' => $userId, 'template_type' => $templateType, 'status' => 'available', 'job_id' => null];
        return $id;
    }
    public function cancelTaskJobs(int $householdId, int $taskId): void
    {
        foreach ($this->jobs as &$job) if ($job['household_id'] === $householdId && ($job['payload']['task_id'] ?? null) === $taskId && $job['status'] === 'pending') $job['status'] = 'cancelled';
    }
    public function upsertJob(int $householdId, int $userId, string $eventKey, string $jobType, string $scheduledAt, array $payload): void
    {
        foreach ($this->jobs as &$job) if ($job['event_key'] === $eventKey) {
            if (!in_array($job['status'], ['sending', 'sent', 'permanent_failed'], true) && !($job['status'] === 'cancelled' && $job['job_type'] === 'inventory_expiry')) {
                $job['status'] = 'pending';
                $job['scheduled_at'] = $scheduledAt;
                $job['payload'] = $payload;
            }
            return;
        }
        $id = $this->nextJob++;
        $this->jobs[$id] = ['id' => $id, 'household_id' => $householdId, 'user_id' => $userId, 'event_key' => $eventKey, 'job_type' => $jobType, 'scheduled_at' => $scheduledAt, 'payload' => $payload, 'attempts' => 0, 'status' => 'pending', 'last_error' => null];
    }
    public function expiryRecipients(string $fromDate, string $throughDate): array { return $this->expiry; }
    public function claimDueJob(string $nowUtc): ?array
    {
        foreach ($this->jobs as &$job) if ($job['status'] === 'pending' && $job['attempts'] < 3 && $job['scheduled_at'] <= $nowUtc) { $job['status'] = 'sending'; return $job + ['openid' => 'openid']; }
        return null;
    }
    public function taskJobIsCurrent(array $job): bool { return $job['job_type'] !== 'task_due' || $this->current; }
    public function claimGrant(int $householdId, int $userId, string $templateType, int $jobId): ?int
    {
        foreach ($this->grants as &$grant) if ($grant['household_id'] === $householdId && $grant['user_id'] === $userId && $grant['template_type'] === $templateType && $grant['status'] === 'available') { $grant['status'] = 'claimed'; $grant['job_id'] = $jobId; return $grant['id']; }
        return null;
    }
    public function cancelClaimedJob(int $jobId): void { $this->jobs[$jobId]['status'] = 'cancelled'; }
    public function recordSendAttempt(int $jobId): void { ++$this->jobs[$jobId]['attempts']; }
    public function markSent(int $jobId, int $grantId): void { $this->jobs[$jobId]['status'] = 'sent'; $this->grants[$grantId]['status'] = 'consumed'; }
    public function markTransientFailure(int $jobId, int $grantId, int $attempts, string $error): void { $this->jobs[$jobId]['status'] = $attempts >= 3 ? 'permanent_failed' : 'pending'; $this->jobs[$jobId]['scheduled_at'] = $attempts >= 3 ? $this->jobs[$jobId]['scheduled_at'] : '9999-12-31 00:00:00.000000'; $this->jobs[$jobId]['last_error'] = $error; $this->grants[$grantId]['status'] = 'available'; }
    public function markPermanentFailure(int $jobId, int $grantId, string $error): void { $this->jobs[$jobId]['status'] = 'permanent_failed'; $this->jobs[$jobId]['last_error'] = $error; $this->grants[$grantId]['status'] = 'consumed'; }
}
