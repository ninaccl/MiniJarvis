<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Task\TaskRepository;

final class InMemoryTaskRepository implements TaskRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $tasks = [];
    public array $operations = [];
    private int $nextId = 1;

    public function lockHousehold(int $householdId): void { $this->operations[] = 'lock-household:' . $householdId; }

    public function listTasks(int $householdId, string $status, ?int $assigneeUserId): array
    {
        return array_values(array_filter($this->tasks, static fn (array $task): bool =>
            $task['household_id'] === $householdId && ($status === 'all' || $task['status'] === $status)
            && ($assigneeUserId === null || $task['assignee_user_id'] === $assigneeUserId)));
    }

    public function findTask(int $householdId, int $taskId, bool $forUpdate = false): ?array
    {
        $this->operations[] = 'find-task';
        $task = $this->tasks[$taskId] ?? null;
        return $task !== null && $task['household_id'] === $householdId ? $task : null;
    }

    public function children(int $householdId, int $parentId, bool $forUpdate = false): array
    {
        return array_values(array_filter($this->tasks, static fn (array $task): bool => $task['household_id'] === $householdId && $task['parent_id'] === $parentId));
    }

    public function createTask(int $householdId, int $creatorUserId, array $task): int
    {
        $this->operations[] = 'create-task';
        $id = $this->nextId++;
        $this->tasks[$id] = ['id' => $id, 'household_id' => $householdId, 'status' => 'pending', 'created_by' => $creatorUserId, 'completed_at' => null, 'created_at' => '2026-09-13T00:00:00.000000Z', 'updated_at' => '2026-09-13T00:00:00.000000Z'] + $task;
        if ($task['due_at'] !== null) $this->tasks[$id]['due_at'] = (new \DateTimeImmutable($task['due_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        return $id;
    }

    public function updateTask(int $householdId, int $taskId, array $fields): void
    {
        foreach ($fields as $key => $value) {
            if ($key === 'due_at' && $value !== null) $value = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
            $this->tasks[$taskId][$key] = $value;
        }
    }

    public function setStatus(int $householdId, int $taskId, string $status): void { $this->tasks[$taskId]['status'] = $status; }
    public function setChildrenStatus(int $householdId, int $parentId, string $status): void
    {
        foreach ($this->tasks as &$task) if ($task['household_id'] === $householdId && $task['parent_id'] === $parentId) $task['status'] = $status;
    }
    public function allChildrenCompleted(int $householdId, int $parentId): bool
    {
        foreach ($this->children($householdId, $parentId) as $task) if ($task['status'] !== 'completed') return false;
        return true;
    }
    public function hasChildren(int $householdId, int $taskId): bool { return $this->children($householdId, $taskId) !== []; }
    public function deleteTask(int $householdId, int $taskId): bool
    {
        $this->operations[] = 'delete-task';
        if ($this->findTask($householdId, $taskId) === null) return false;
        unset($this->tasks[$taskId]);
        foreach ($this->tasks as $id => $task) if ($task['household_id'] === $householdId && $task['parent_id'] === $taskId) unset($this->tasks[$id]);
        return true;
    }
}
