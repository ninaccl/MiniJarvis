<?php

declare(strict_types=1);

namespace Tests\Task;

use App\Auth\AuthContext;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Task\TaskService;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryHouseholdStore;
use Tests\Support\InMemoryNotificationRepository;
use Tests\Support\InMemoryTaskRepository;
use Tests\Support\MultiSnapshotTransactionManager;

final class TaskServiceTest extends TestCase
{
    private InMemoryTaskRepository $tasks;
    private InMemoryNotificationRepository $notifications;
    private TaskService $service;
    private AuthContext $owner;

    protected function setUp(): void
    {
        $households = new InMemoryHouseholdStore();
        $households->create('Home', 1, hash('sha256', 'invite'));
        $households->addMember(1, 2);
        $this->tasks = new InMemoryTaskRepository();
        $this->notifications = new InMemoryNotificationRepository();
        $this->service = new TaskService(new MultiSnapshotTransactionManager([$this->tasks, $this->notifications]), $this->tasks, $households, $this->notifications, new TenantGuard());
        $this->owner = new AuthContext(1, 1, 'owner');
    }

    public function testRejectsChildOfChildAndCrossHouseholdAssignee(): void
    {
        $parent = $this->create('Parent');
        $child = $this->create('Child', ['parent_id' => $parent['id']]);
        $this->assertApiError(fn () => $this->create('Grandchild', ['parent_id' => $child['id']]), 'VALIDATION_FAILED');
        $this->assertApiError(fn () => $this->create('Assigned', ['assignee_user_id' => 99]), 'VALIDATION_FAILED');
    }

    public function testUpdateRejectsMakingTaskItsOwnParentWithoutMutatingIt(): void
    {
        $task = $this->create('Independent');

        try {
            $this->service->update($this->owner, $task['id'], ['parent_id' => $task['id']]);
            self::fail('Expected self-parent assignment to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('VALIDATION_FAILED', $exception->errorCode());
            self::assertArrayHasKey('parent_id', $exception->fields());
        }

        self::assertNull($this->tasks->tasks[$task['id']]['parent_id']);
    }

    public function testNormalizesOffsetDeadlineToUtcAndRescheduleChangesEventKey(): void
    {
        $task = $this->create('Dinner', ['assignee_user_id' => 2, 'due_at' => '2026-09-15T20:00:00+08:00']);
        self::assertSame('2026-09-15T12:00:00.000000Z', $task['due_at']);
        $first = array_values($this->notifications->jobs)[0];
        self::assertSame('2026-09-14 12:00:00.000000', $first['scheduled_at']);
        $this->service->update($this->owner, $task['id'], ['due_at' => '2026-09-16T20:00:00+08:00']);
        self::assertCount(2, $this->notifications->jobs);
        self::assertSame('cancelled', $this->notifications->jobs[$first['id']]['status']);
        self::assertNotSame($first['event_key'], array_values($this->notifications->jobs)[1]['event_key']);
    }

    public function testParentCompletionCompletesChildrenAndCancelsAllReminders(): void
    {
        $parent = $this->create('Parent', $this->due());
        $one = $this->create('One', ['parent_id' => $parent['id']] + $this->due());
        $two = $this->create('Two', ['parent_id' => $parent['id']] + $this->due());
        $this->service->update($this->owner, $parent['id'], ['status' => 'completed']);
        foreach ([$parent['id'], $one['id'], $two['id']] as $id) self::assertSame('completed', $this->tasks->tasks[$id]['status']);
        foreach ($this->notifications->jobs as $job) self::assertSame('cancelled', $job['status']);
    }

    public function testFinalChildCompletesParentAndReopeningChildReopensParent(): void
    {
        $parent = $this->create('Parent');
        $one = $this->create('One', ['parent_id' => $parent['id']]);
        $two = $this->create('Two', ['parent_id' => $parent['id']]);
        $this->service->update($this->owner, $one['id'], ['status' => 'completed']);
        self::assertSame('pending', $this->tasks->tasks[$parent['id']]['status']);
        $this->service->update($this->owner, $two['id'], ['status' => 'completed']);
        self::assertSame('completed', $this->tasks->tasks[$parent['id']]['status']);
        $this->service->update($this->owner, $one['id'], ['status' => 'pending']);
        self::assertSame('pending', $this->tasks->tasks[$parent['id']]['status']);
    }

    public function testReopeningParentDoesNotReopenChildrenAndDeleteParentCascades(): void
    {
        $parent = $this->create('Parent');
        $child = $this->create('Child', ['parent_id' => $parent['id']]);
        $this->service->update($this->owner, $parent['id'], ['status' => 'completed']);
        $this->service->update($this->owner, $parent['id'], ['status' => 'pending']);
        self::assertSame('completed', $this->tasks->tasks[$child['id']]['status']);
        $this->service->delete($this->owner, $parent['id']);
        self::assertSame([], $this->tasks->tasks);
    }

    public function testEveryTaskMutationLocksHouseholdBeforeAnyTaskReadOrWrite(): void
    {
        $task = $this->create('Serialized');
        self::assertSame('lock-household:1', $this->tasks->operations[0]);
        $this->tasks->operations = [];
        $this->service->update($this->owner, $task['id'], ['status' => 'completed']);
        self::assertSame('lock-household:1', $this->tasks->operations[0]);
        $this->tasks->operations = [];
        $this->service->delete($this->owner, $task['id']);
        self::assertSame('lock-household:1', $this->tasks->operations[0]);
    }

    public function testExplicitlyReopeningTaskRequeuesItsCancelledReminder(): void
    {
        $task = $this->create('Reopen', $this->due());
        $this->service->update($this->owner, $task['id'], ['status' => 'completed']);
        self::assertSame('cancelled', $this->notifications->jobs[1]['status']);
        $this->service->update($this->owner, $task['id'], ['status' => 'pending']);
        self::assertSame('pending', $this->notifications->jobs[1]['status']);
        self::assertCount(1, $this->notifications->jobs);
    }

    private function create(string $title, array $extra = []): array { return $this->service->create($this->owner, ['title' => $title] + $extra); }
    private function due(): array { return ['assignee_user_id' => 2, 'due_at' => '2026-09-20T09:00:00Z']; }
    private function assertApiError(callable $action, string $code): void
    {
        try { $action(); self::fail('Expected API error.'); } catch (ApiException $exception) { self::assertSame($code, $exception->errorCode()); }
    }
}
