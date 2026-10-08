<?php

namespace App\Task;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
final class PdoTaskRepository implements TaskRepository
{
    private $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function lockHousehold($householdId)
    {
        $statement = $this->pdo->prepare('SELECT id FROM jarvis_households WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $householdId]);
        $statement->fetchColumn();
    }
    public function listTasks($householdId, $status, $assigneeUserId)
    {
        $sql = $this->select() . ' WHERE t.household_id = :household_id';
        $params = ['household_id' => $householdId];
        if ($status !== 'all') {
            $sql .= ' AND t.status = :status';
            $params['status'] = $status;
        }
        if ($assigneeUserId !== null) {
            $sql .= ' AND t.assigned_to = :assignee';
            $params['assignee'] = $assigneeUserId;
        }
        $sql .= ' ORDER BY (t.parent_id IS NOT NULL), COALESCE(t.parent_id, t.id), t.id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map([$this, 'row'], $statement->fetchAll());
    }
    public function findTask($householdId, $taskId, $forUpdate = false)
    {
        $statement = $this->pdo->prepare($this->select() . ' WHERE t.household_id = :household_id AND t.id = :id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute(['household_id' => $householdId, 'id' => $taskId]);
        $row = $statement->fetch();
        return $row === false ? null : $this->row($row);
    }
    public function children($householdId, $parentId, $forUpdate = false)
    {
        $statement = $this->pdo->prepare($this->select() . ' WHERE t.household_id = :household_id AND t.parent_id = :parent_id ORDER BY t.id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute(['household_id' => $householdId, 'parent_id' => $parentId]);
        return array_map([$this, 'row'], $statement->fetchAll());
    }
    public function createTask($householdId, $creatorUserId, array $task)
    {
        $statement = $this->pdo->prepare('INSERT INTO jarvis_tasks (household_id, title, due_at, parent_id, assigned_household_id, assigned_to, created_by) ' . 'VALUES (:household_id, :title, :due_at, :parent_id, :assigned_household_id, :assigned_to, :created_by)');
        $statement->execute(['household_id' => $householdId, 'title' => $task['title'], 'due_at' => $task['due_at'], 'parent_id' => $task['parent_id'], 'assigned_household_id' => $task['assignee_user_id'] === null ? null : $householdId, 'assigned_to' => $task['assignee_user_id'], 'created_by' => $creatorUserId]);
        return (int) $this->pdo->lastInsertId();
    }
    public function updateTask($householdId, $taskId, array $fields)
    {
        $sets = [];
        $params = ['household_id' => $householdId, 'id' => $taskId];
        foreach (['title', 'due_at', 'parent_id'] as $field) {
            if (array_key_exists($field, $fields)) {
                $sets[] = $field . ' = :' . $field;
                $params[$field] = $fields[$field];
            }
        }
        if (array_key_exists('assignee_user_id', $fields)) {
            $sets[] = 'assigned_to = :assigned_to';
            $sets[] = 'assigned_household_id = :assigned_household_id';
            $params['assigned_to'] = $fields['assignee_user_id'];
            $params['assigned_household_id'] = $fields['assignee_user_id'] === null ? null : $householdId;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP(6)';
        $statement = $this->pdo->prepare('UPDATE jarvis_tasks SET ' . implode(', ', $sets) . ' WHERE household_id = :household_id AND id = :id');
        $statement->execute($params);
    }
    public function setStatus($householdId, $taskId, $status)
    {
        $statement = $this->pdo->prepare("UPDATE jarvis_tasks SET status = :status, completed_at = IF(:completion_status = 'completed', CURRENT_TIMESTAMP(6), NULL), updated_at = CURRENT_TIMESTAMP(6) " . 'WHERE household_id = :household_id AND id = :id');
        $statement->execute(['status' => $status, 'completion_status' => $status, 'household_id' => $householdId, 'id' => $taskId]);
    }
    public function setChildrenStatus($householdId, $parentId, $status)
    {
        $statement = $this->pdo->prepare("UPDATE jarvis_tasks SET status = :status, completed_at = IF(:completion_status = 'completed', CURRENT_TIMESTAMP(6), NULL), updated_at = CURRENT_TIMESTAMP(6) " . 'WHERE household_id = :household_id AND parent_id = :parent_id');
        $statement->execute(['status' => $status, 'completion_status' => $status, 'household_id' => $householdId, 'parent_id' => $parentId]);
    }
    public function allChildrenCompleted($householdId, $parentId)
    {
        // A locking read sees committed sibling changes even under MySQL REPEATABLE READ.
        foreach ($this->children($householdId, $parentId, true) as $child) {
            if ($child['status'] !== 'completed') {
                return false;
            }
        }
        return true;
    }
    public function hasChildren($householdId, $taskId)
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM jarvis_tasks WHERE household_id = :household_id AND parent_id = :id LIMIT 1');
        $statement->execute(['household_id' => $householdId, 'id' => $taskId]);
        return $statement->fetchColumn() !== false;
    }
    public function deleteTask($householdId, $taskId)
    {
        $statement = $this->pdo->prepare('DELETE FROM jarvis_tasks WHERE household_id = :household_id AND id = :id');
        $statement->execute(['household_id' => $householdId, 'id' => $taskId]);
        return $statement->rowCount() === 1;
    }
    private function select()
    {
        return 'SELECT t.id, t.household_id, t.title, t.status, t.due_at, t.parent_id, t.assigned_to AS assignee_user_id, ' . 't.created_by, t.completed_at, t.created_at, t.updated_at FROM jarvis_tasks t';
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function row(array $row)
    {
        return ['id' => (int) $row['id'], 'household_id' => (int) $row['household_id'], 'title' => (string) $row['title'], 'status' => (string) $row['status'], 'due_at' => $this->timestamp($row['due_at']), 'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'], 'assignee_user_id' => $row['assignee_user_id'] === null ? null : (int) $row['assignee_user_id'], 'created_by' => (int) $row['created_by'], 'completed_at' => $this->timestamp($row['completed_at']), 'created_at' => $this->timestamp($row['created_at']), 'updated_at' => $this->timestamp($row['updated_at'])];
    }
    private function timestamp($value)
    {
        if ($value === null) {
            return null;
        }
        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
