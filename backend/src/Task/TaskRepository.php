<?php

declare(strict_types=1);

namespace App\Task;

interface TaskRepository
{
    /** First operation in every task mutation transaction; serializes household task trees. */
    public function lockHousehold(int $householdId): void;
    /** @return list<array<string,mixed>> */
    public function listTasks(int $householdId, string $status, ?int $assigneeUserId): array;
    /** @return array<string,mixed>|null */
    public function findTask(int $householdId, int $taskId, bool $forUpdate = false): ?array;
    /** @return list<array<string,mixed>> */
    public function children(int $householdId, int $parentId, bool $forUpdate = false): array;
    /** @param array<string,mixed> $task */
    public function createTask(int $householdId, int $creatorUserId, array $task): int;
    /** @param array<string,mixed> $fields */
    public function updateTask(int $householdId, int $taskId, array $fields): void;
    public function setStatus(int $householdId, int $taskId, string $status): void;
    public function setChildrenStatus(int $householdId, int $parentId, string $status): void;
    public function allChildrenCompleted(int $householdId, int $parentId): bool;
    public function hasChildren(int $householdId, int $taskId): bool;
    public function deleteTask(int $householdId, int $taskId): bool;
}
