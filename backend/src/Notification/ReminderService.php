<?php

declare(strict_types=1);

namespace App\Notification;

use App\Auth\AuthContext;
use App\Database\TransactionManager;
use App\Household\TenantGuard;
use App\Http\ApiException;
use DateTimeImmutable;
use DateTimeZone;

final class ReminderService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly NotificationRepository $notifications,
        private readonly NotificationSender $sender,
        private readonly TenantGuard $guard,
    ) {
    }

    /** @return array{task_due:bool,inventory_expiry:bool} */
    public function preferences(AuthContext $context): array
    {
        $householdId = $this->guard->requireMembership($context);
        return $this->notifications->preferences($householdId, $context->userId);
    }

    /** @return array{task_due:bool,inventory_expiry:bool} */
    public function updatePreferences(AuthContext $context, array $payload): array
    {
        $householdId = $this->guard->requireMembership($context);
        $errors = [];
        if (!array_key_exists('task_due', $payload) && !array_key_exists('inventory_expiry', $payload)) $errors['payload'] = 'Provide task_due or inventory_expiry.';
        foreach (['task_due', 'inventory_expiry'] as $field) if (array_key_exists($field, $payload) && !is_bool($payload[$field])) $errors[$field] = 'Must be boolean.';
        if ($errors !== []) throw new ApiException(422, 'VALIDATION_FAILED', 'Notification preferences are invalid.', $errors);
        $this->notifications->updatePreferences($householdId, $context->userId, $payload['task_due'] ?? null, $payload['inventory_expiry'] ?? null);
        return $this->notifications->preferences($householdId, $context->userId);
    }

    /** @return array{accepted:bool,grant_id:?int} */
    public function recordGrant(AuthContext $context, array $payload): array
    {
        $householdId = $this->guard->requireMembership($context);
        $type = $payload['template_type'] ?? null;
        $result = $payload['result'] ?? null;
        $errors = [];
        if (!is_string($type) || !in_array($type, ['task_due', 'inventory_expiry'], true)) $errors['template_type'] = 'Must be task_due or inventory_expiry.';
        if (!is_string($result) || !in_array($result, ['accept', 'reject'], true)) $errors['result'] = 'Must be accept or reject.';
        if ($errors !== []) throw new ApiException(422, 'VALIDATION_FAILED', 'Subscription result is invalid.', $errors);
        if ($result === 'reject') return ['accepted' => false, 'grant_id' => null];
        $grantId = $this->transactions->transaction(fn (): int => $this->notifications->addGrant($householdId, $context->userId, $type));
        return ['accepted' => true, 'grant_id' => $grantId];
    }

    public function materializeExpiry(DateTimeImmutable $now): int
    {
        // Inventory dates are the sole local-calendar rule; all persisted schedules remain UTC.
        $timezone = new DateTimeZone('Asia/Shanghai');
        $local = $now->setTimezone($timezone);
        $date = $local->format('Y-m-d');
        $through = $local->modify('+3 days')->format('Y-m-d');
        $scheduled = (new DateTimeImmutable($date . ' 09:00:00', $timezone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $count = 0;
        foreach ($this->notifications->expiryRecipients($date, $through) as $recipient) {
            $parts = array_map(static fn (array $item): string => $item['name'] . '(' . $item['expires_on'] . ')', $recipient['items']);
            $payload = ['local_date' => $date, 'summary' => implode('、', $parts), 'items' => $recipient['items']];
            $eventKey = sprintf('inventory_expiry:%d:%d:%s', $recipient['household_id'], $recipient['user_id'], $date);
            $this->notifications->upsertJob($recipient['household_id'], $recipient['user_id'], $eventKey, 'inventory_expiry', $scheduled, $payload);
            ++$count;
        }
        return $count;
    }

    /** @return array{claimed:int,sent:int,retry:int,failed:int,skipped_no_grant:int,cancelled_stale:int} */
    public function deliverDue(DateTimeImmutable $now, int $limit = 100): array
    {
        $stats = ['claimed' => 0, 'sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped_no_grant' => 0, 'cancelled_stale' => 0];
        $utc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        for ($i = 0; $i < $limit; ++$i) {
            $claimed = $this->transactions->transaction(function () use ($utc): ?array {
                $job = $this->notifications->claimDueJob($utc);
                if ($job === null) return null;
                if (!$this->notifications->taskJobIsCurrent($job)) {
                    $this->notifications->cancelClaimedJob($job['id']);
                    return ['outcome' => 'cancelled_stale'];
                }
                $grant = $this->notifications->claimGrant($job['household_id'], $job['user_id'], $job['job_type'], $job['id']);
                if ($grant === null) {
                    $this->notifications->cancelClaimedJob($job['id']);
                    return ['outcome' => 'skipped_no_grant'];
                }
                $this->notifications->recordSendAttempt($job['id']);
                ++$job['attempts'];
                return ['outcome' => 'send', 'job' => $job, 'grant_id' => $grant];
            });
            if ($claimed === null) break;
            ++$stats['claimed'];
            if ($claimed['outcome'] !== 'send') {
                ++$stats[$claimed['outcome']];
                continue;
            }
            $result = $this->sender->send($claimed['job']);
            if ($result->sent) {
                $this->transactions->transaction(fn () => $this->notifications->markSent($claimed['job']['id'], $claimed['grant_id']));
                ++$stats['sent'];
            } elseif ($result->transient) {
                $this->transactions->transaction(fn () => $this->notifications->markTransientFailure($claimed['job']['id'], $claimed['grant_id'], $claimed['job']['attempts'], $result->error));
                ++$stats[$claimed['job']['attempts'] >= 3 ? 'failed' : 'retry'];
            } else {
                $this->transactions->transaction(fn () => $this->notifications->markPermanentFailure($claimed['job']['id'], $claimed['grant_id'], $result->error));
                ++$stats['failed'];
            }
        }
        return $stats;
    }
}
