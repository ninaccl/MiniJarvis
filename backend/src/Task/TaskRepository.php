<?php

namespace App\Task;

interface TaskRepository
{
    /** First operation in every task mutation transaction; serializes household task trees. */
    public function lockHousehold($householdId);
    /** @return list<array<string,mixed>> */
    public function listTasks($householdId, $status, $assigneeUserId);
    /** @return array<string,mixed>|null */
    public function findTask($householdId, $taskId, $forUpdate = false);
    /** @return list<array<string,mixed>> */
    public function children($householdId, $parentId, $forUpdate = false);
    /** @param array<string,mixed> $task */
    public function createTask($householdId, $creatorUserId, array $task);
    /** @param array<string,mixed> $fields */
    public function updateTask($householdId, $taskId, array $fields);
    public function setStatus($householdId, $taskId, $status);
    public function setChildrenStatus($householdId, $parentId, $status);
    public function allChildrenCompleted($householdId, $parentId);
    public function hasChildren($householdId, $taskId);
    public function deleteTask($householdId, $taskId);
}
