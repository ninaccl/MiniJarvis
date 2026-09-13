<?php

declare(strict_types=1);

namespace App\Task;

use App\Auth\AuthContext;
use App\Database\TransactionManager;
use App\Household\HouseholdStore;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Notification\NotificationRepository;
use App\Support\Text;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class TaskService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly TaskRepository $tasks,
        private readonly HouseholdStore $households,
        private readonly NotificationRepository $notifications,
        private readonly TenantGuard $guard,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(AuthContext $context, string $status = 'all', ?int $assigneeUserId = null): array
    {
        $householdId = $this->guard->requireMembership($context);
        if (!in_array($status, ['all', 'pending', 'completed'], true)) $this->invalid(['status' => 'Must be all, pending, or completed.']);
        if ($assigneeUserId !== null && $this->households->member($householdId, $assigneeUserId) === null) {
            $this->invalid(['assignee_id' => 'Assignee must be a current household member.']);
        }
        return $this->tasks->listTasks($householdId, $status, $assigneeUserId);
    }

    /** @return array<string,mixed> */
    public function get(AuthContext $context, int $taskId): array
    {
        $householdId = $this->guard->requireMembership($context);
        $task = $this->requireTask($householdId, $taskId);
        return $task + ['children' => $this->tasks->children($householdId, $taskId)];
    }

    /** @return array<string,mixed> */
    public function create(AuthContext $context, array $payload): array
    {
        $householdId = $this->guard->requireMembership($context);
        return $this->transactions->transaction(function () use ($context, $householdId, $payload): array {
            $this->tasks->lockHousehold($householdId);
            $fields = $this->validate($householdId, $payload, true);
            $id = $this->tasks->createTask($householdId, $context->userId, $fields);
            $task = $this->requireTask($householdId, $id);
            $this->syncReminder($task);
            return $task;
        });
    }

    /** @return array<string,mixed> */
    public function update(AuthContext $context, int $taskId, array $payload): array
    {
        $householdId = $this->guard->requireMembership($context);
        return $this->transactions->transaction(function () use ($householdId, $taskId, $payload): array {
            $this->tasks->lockHousehold($householdId);
            $task = $this->tasks->findTask($householdId, $taskId, true) ?? throw $this->notFound();
            $fields = $this->validate($householdId, $payload, false, $taskId);
            $status = $fields['status'] ?? null;
            unset($fields['status']);
            $this->tasks->updateTask($householdId, $taskId, $fields);
            $task = $this->requireTask($householdId, $taskId);

            $affected = [$taskId];
            if ($status !== null && $status !== $task['status']) {
                if ($status === 'completed') {
                    $this->tasks->setStatus($householdId, $taskId, 'completed');
                    if ($task['parent_id'] === null) {
                        $children = $this->tasks->children($householdId, $taskId, true);
                        $this->tasks->setChildrenStatus($householdId, $taskId, 'completed');
                        foreach ($children as $child) $affected[] = $child['id'];
                    } else {
                        $this->tasks->findTask($householdId, $task['parent_id'], true);
                        if ($this->tasks->allChildrenCompleted($householdId, $task['parent_id'])) {
                            $this->tasks->setStatus($householdId, $task['parent_id'], 'completed');
                            $affected[] = $task['parent_id'];
                        }
                    }
                } else {
                    $this->tasks->setStatus($householdId, $taskId, 'pending');
                    if ($task['parent_id'] !== null) {
                        $this->tasks->findTask($householdId, $task['parent_id'], true);
                        $this->tasks->setStatus($householdId, $task['parent_id'], 'pending');
                        $affected[] = $task['parent_id'];
                    }
                }
            }
            foreach (array_unique($affected) as $id) $this->syncReminder($this->requireTask($householdId, (int) $id));
            return $this->requireTask($householdId, $taskId);
        });
    }

    public function delete(AuthContext $context, int $taskId): void
    {
        $householdId = $this->guard->requireMembership($context);
        $this->transactions->transaction(function () use ($householdId, $taskId): void {
            $this->tasks->lockHousehold($householdId);
            $task = $this->tasks->findTask($householdId, $taskId, true) ?? throw $this->notFound();
            $affected = [$taskId];
            foreach ($this->tasks->children($householdId, $taskId, true) as $child) $affected[] = $child['id'];
            foreach ($affected as $id) $this->notifications->cancelTaskJobs($householdId, (int) $id);
            if (!$this->tasks->deleteTask($householdId, $taskId)) throw $this->notFound();
        });
    }

    /** @return array<string,mixed> */
    private function validate(int $householdId, array $payload, bool $creating, ?int $taskId = null): array
    {
        $allowed = ['title', 'due_at', 'assignee_user_id', 'parent_id', 'status'];
        $provided = array_intersect($allowed, array_keys($payload));
        $errors = [];
        if (!$creating && $provided === []) $errors['payload'] = 'Provide at least one task field.';
        $fields = [];
        if ($creating || array_key_exists('title', $payload)) {
            $title = $payload['title'] ?? null;
            if (!is_string($title) || Text::length(trim($title)) < 1 || Text::length(trim($title)) > 200) $errors['title'] = 'Must be 1-200 characters.';
            else $fields['title'] = trim($title);
        }
        if ($creating || array_key_exists('due_at', $payload)) {
            $due = $payload['due_at'] ?? null;
            if ($due !== null && !is_string($due)) $errors['due_at'] = 'Must be an ISO-8601 instant with an offset, or null.';
            else {
                try {
                    if ($due !== null && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $due) !== 1) throw new \RuntimeException();
                    $fields['due_at'] = $due === null ? null : (new DateTimeImmutable($due))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
                } catch (Throwable) {
                    $errors['due_at'] = 'Must be an ISO-8601 instant with an offset, or null.';
                }
            }
        }
        if ($creating || array_key_exists('assignee_user_id', $payload)) {
            $assignee = $payload['assignee_user_id'] ?? null;
            if ($assignee !== null && (!is_int($assignee) || $assignee < 1)) $errors['assignee_user_id'] = 'Must be a positive user id or null.';
            elseif ($assignee !== null && $this->households->member($householdId, $assignee) === null) $errors['assignee_user_id'] = 'Assignee must be a current household member.';
            else $fields['assignee_user_id'] = $assignee;
        }
        if ($creating || array_key_exists('parent_id', $payload)) {
            $parentId = $payload['parent_id'] ?? null;
            if ($parentId !== null && (!is_int($parentId) || $parentId < 1 || $parentId === $taskId)) $errors['parent_id'] = 'Must reference a top-level household task.';
            elseif ($parentId !== null) {
                $parent = $this->tasks->findTask($householdId, $parentId, true);
                if ($parent === null || $parent['parent_id'] !== null || ($taskId !== null && $this->tasks->hasChildren($householdId, $taskId))) {
                    $errors['parent_id'] = 'Must reference a top-level household task; tasks with children cannot become children.';
                } else $fields['parent_id'] = $parentId;
            } else $fields['parent_id'] = null;
        }
        if (!$creating && array_key_exists('status', $payload)) {
            if (!is_string($payload['status']) || !in_array($payload['status'], ['pending', 'completed'], true)) $errors['status'] = 'Must be pending or completed.';
            else $fields['status'] = $payload['status'];
        }
        if ($errors !== []) $this->invalid($errors);
        return $fields;
    }

    /** @param array<string,mixed> $task */
    private function syncReminder(array $task): void
    {
        $this->notifications->cancelTaskJobs((int) $task['household_id'], (int) $task['id']);
        if ($task['status'] !== 'pending' || $task['due_at'] === null || $task['assignee_user_id'] === null) return;
        $due = new DateTimeImmutable($task['due_at']);
        $eventKey = sprintf('task_due:%d:%d:%s', $task['id'], $task['assignee_user_id'], hash('sha256', $task['due_at']));
        $this->notifications->upsertJob(
            (int) $task['household_id'], (int) $task['assignee_user_id'], $eventKey, 'task_due',
            $due->modify('-24 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ['task_id' => $task['id'], 'title' => $task['title'], 'due_at' => $task['due_at'], 'assignee_user_id' => $task['assignee_user_id']],
        );
    }

    /** @return array<string,mixed> */
    private function requireTask(int $householdId, int $taskId): array
    {
        return $this->tasks->findTask($householdId, $taskId) ?? throw $this->notFound();
    }

    /** @param array<string,string> $fields */
    private function invalid(array $fields): never
    {
        throw new ApiException(422, 'VALIDATION_FAILED', 'Task data is invalid.', $fields);
    }

    private function notFound(): ApiException
    {
        return new ApiException(404, 'TASK_NOT_FOUND', 'The task was not found.');
    }
}
