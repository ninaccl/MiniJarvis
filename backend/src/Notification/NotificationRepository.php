<?php

namespace App\Notification;

interface NotificationRepository
{
    /** @return array{task_due:bool,inventory_expiry:bool} */
    public function preferences($householdId, $userId);
    public function updatePreferences($householdId, $userId, $taskDue, $inventoryExpiry);
    public function addGrant($householdId, $userId, $templateType);
    public function cancelTaskJobs($householdId, $taskId);
    /** @param array<string,mixed> $payload */
    public function upsertJob($householdId, $userId, $eventKey, $jobType, $scheduledAt, array $payload);
    /** @return list<array{household_id:int,user_id:int,items:list<array<string,mixed>>}> */
    public function expiryRecipients($fromDate, $throughDate);
    /** @return array<string,mixed>|null */
    public function claimDueJob($nowUtc);
    /** @param array<string,mixed> $job */
    public function taskJobIsCurrent(array $job);
    public function claimGrant($householdId, $userId, $templateType, $jobId);
    /** Record only an actual send attempt, after validating the task and claiming a grant. */
    public function recordSendAttempt($jobId);
    public function cancelClaimedJob($jobId);
    public function markSent($jobId, $grantId);
    public function markTransientFailure($jobId, $grantId, $attempts, $error);
    public function markPermanentFailure($jobId, $grantId, $error);
}
