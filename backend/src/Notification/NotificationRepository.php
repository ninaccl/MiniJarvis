<?php

declare(strict_types=1);

namespace App\Notification;

interface NotificationRepository
{
    /** @return array{task_due:bool,inventory_expiry:bool} */
    public function preferences(int $householdId, int $userId): array;
    public function updatePreferences(int $householdId, int $userId, ?bool $taskDue, ?bool $inventoryExpiry): void;
    public function addGrant(int $householdId, int $userId, string $templateType): int;
    public function cancelTaskJobs(int $householdId, int $taskId): void;
    /** @param array<string,mixed> $payload */
    public function upsertJob(int $householdId, int $userId, string $eventKey, string $jobType, string $scheduledAt, array $payload): void;
    /** @return list<array{household_id:int,user_id:int,items:list<array<string,mixed>>}> */
    public function expiryRecipients(string $fromDate, string $throughDate): array;
    /** @return array<string,mixed>|null */
    public function claimDueJob(string $nowUtc): ?array;
    /** @param array<string,mixed> $job */
    public function taskJobIsCurrent(array $job): bool;
    public function claimGrant(int $householdId, int $userId, string $templateType, int $jobId): ?int;
    /** Record only an actual send attempt, after validating the task and claiming a grant. */
    public function recordSendAttempt(int $jobId): void;
    public function cancelClaimedJob(int $jobId): void;
    public function markSent(int $jobId, int $grantId): void;
    public function markTransientFailure(int $jobId, int $grantId, int $attempts, string $error): void;
    public function markPermanentFailure(int $jobId, int $grantId, string $error): void;
}
