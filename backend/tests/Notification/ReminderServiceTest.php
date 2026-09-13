<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Auth\AuthContext;
use App\Household\TenantGuard;
use App\Notification\ReminderService;
use App\Notification\SendResult;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryNotificationRepository;
use Tests\Support\InMemoryTransactionManager;
use Tests\Support\QueueNotificationSender;

final class ReminderServiceTest extends TestCase
{
    private InMemoryNotificationRepository $repository;
    private QueueNotificationSender $sender;
    private ReminderService $service;
    private AuthContext $context;

    protected function setUp(): void
    {
        $this->repository = new InMemoryNotificationRepository();
        $this->sender = new QueueNotificationSender();
        $this->service = new ReminderService(new InMemoryTransactionManager(), $this->repository, $this->sender, new TenantGuard());
        $this->context = new AuthContext(2, 1, 'member');
    }

    public function testAcceptCreatesExactlyOneGrantAndRejectCreatesNone(): void
    {
        $this->service->recordGrant($this->context, ['template_type' => 'task_due', 'result' => 'reject']);
        self::assertCount(0, $this->repository->grants);
        $this->service->recordGrant($this->context, ['template_type' => 'task_due', 'result' => 'accept']);
        self::assertCount(1, $this->repository->grants);
    }

    public function testExpirySummaryGroupsPerMemberAndShanghaiDateAtUtcBoundary(): void
    {
        $this->repository->expiry = [['household_id' => 1, 'user_id' => 2, 'items' => [
            ['batch_id' => 1, 'name' => 'Milk', 'expires_on' => '2026-09-14'],
            ['batch_id' => 2, 'name' => 'Egg', 'expires_on' => '2026-09-17'],
        ]]];
        $this->service->materializeExpiry(new \DateTimeImmutable('2026-09-13T16:30:00Z'));
        $job = array_values($this->repository->jobs)[0];
        self::assertSame('inventory_expiry:1:2:2026-09-14', $job['event_key']);
        self::assertSame('2026-09-14 01:00:00.000000', $job['scheduled_at']);
        $this->service->materializeExpiry(new \DateTimeImmutable('2026-09-13T17:00:00Z'));
        self::assertCount(1, $this->repository->jobs);
    }

    public function testGrantIsConsumedOnceOnSuccessAndNoGrantIsObservableNonError(): void
    {
        $this->job();
        $stats = $this->service->deliverDue(new \DateTimeImmutable('2026-09-14T00:00:00Z'));
        self::assertSame(1, $stats['skipped_no_grant']);
        self::assertNull($this->repository->jobs[1]['last_error']);

        $this->job('second');
        $this->repository->addGrant(1, 2, 'task_due');
        $stats = $this->service->deliverDue(new \DateTimeImmutable('2026-09-14T00:00:00Z'));
        self::assertSame(1, $stats['sent']);
        self::assertSame('consumed', $this->repository->grants[1]['status']);
    }

    public function testTransientFailureRestoresGrantAndThirdFailureIsTerminal(): void
    {
        $this->job();
        $this->repository->addGrant(1, 2, 'task_due');
        $this->sender->results = [SendResult::transient('timeout')];
        $this->service->deliverDue(new \DateTimeImmutable('2026-09-14T00:00:00Z'));
        self::assertSame('available', $this->repository->grants[1]['status']);
        self::assertSame('pending', $this->repository->jobs[1]['status']);
        $this->repository->jobs[1]['attempts'] = 2;
        $this->repository->jobs[1]['scheduled_at'] = '2026-09-13 00:00:00.000000';
        $this->sender->results = [SendResult::transient('timeout')];
        $this->service->deliverDue(new \DateTimeImmutable('2026-09-14T00:00:00Z'));
        self::assertSame('permanent_failed', $this->repository->jobs[1]['status']);
        self::assertSame('available', $this->repository->grants[1]['status']);
    }

    public function testPermanentTemplateErrorConsumesGrantAndDoesNotRetry(): void
    {
        $this->job();
        $this->repository->addGrant(1, 2, 'task_due');
        $this->sender->results = [SendResult::permanent('bad template')];
        $this->service->deliverDue(new \DateTimeImmutable('2026-09-14T00:00:00Z'));
        self::assertSame('permanent_failed', $this->repository->jobs[1]['status']);
        self::assertSame('consumed', $this->repository->grants[1]['status']);
    }

    private function job(string $key = 'first'): void
    {
        $this->repository->upsertJob(1, 2, 'task_due:' . $key, 'task_due', '2026-09-13 00:00:00.000000', ['task_id' => 1, 'title' => 'Task', 'due_at' => '2026-09-15T00:00:00Z']);
    }
}
