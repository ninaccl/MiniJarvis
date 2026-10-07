<?php

namespace App\Household;

use PDO;
use PDOException;
final class PdoHouseholdStore implements HouseholdStore
{
    private $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function membershipForUser($userId)
    {
        $statement = $this->pdo->prepare('SELECT hm.household_id, hm.user_id, hm.role, u.nickname, u.avatar_url ' . 'FROM jarvis_household_members hm JOIN jarvis_users u ON u.id = hm.user_id WHERE hm.user_id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        return $this->memberRow($statement->fetch());
    }
    public function householdsForUser($userId)
    {
        $statement = $this->pdo->prepare('SELECT h.id, h.name, h.owner_user_id, h.invite_code_hash, hm.role FROM jarvis_household_members hm JOIN jarvis_households h ON h.id = hm.household_id WHERE hm.user_id = :user_id ORDER BY h.id');
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function inviteHashExists($inviteHash)
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM jarvis_households WHERE invite_code_hash = :hash LIMIT 1');
        $statement->execute(['hash' => $inviteHash]);
        return $statement->fetchColumn() !== false;
    }
    public function create($name, $ownerUserId, $inviteHash)
    {
        try {
            $statement = $this->pdo->prepare('INSERT INTO jarvis_households (name, owner_user_id, invite_code_hash) VALUES (:name, :owner, :hash)');
            $statement->execute(['name' => $name, 'owner' => $ownerUserId, 'hash' => $inviteHash]);
            $householdId = (int) $this->pdo->lastInsertId();
            $member = $this->pdo->prepare("INSERT INTO jarvis_household_members (household_id, user_id, role) VALUES (:household_id, :user_id, 'owner')");
            $member->execute(['household_id' => $householdId, 'user_id' => $ownerUserId]);
            return $householdId;
        } catch (PDOException $exception) {
            PdoConstraintMapper::rethrow($exception);
        }
    }
    public function householdByInviteHash($inviteHash)
    {
        $statement = $this->pdo->prepare('SELECT id, name, owner_user_id, invite_code_hash FROM jarvis_households WHERE invite_code_hash = :hash LIMIT 1');
        $statement->execute(['hash' => $inviteHash]);
        return $this->householdRow($statement->fetch());
    }
    public function addMember($householdId, $userId)
    {
        try {
            $statement = $this->pdo->prepare("INSERT INTO jarvis_household_members (household_id, user_id, role) VALUES (:household_id, :user_id, 'member')");
            $statement->execute(['household_id' => $householdId, 'user_id' => $userId]);
        } catch (PDOException $exception) {
            PdoConstraintMapper::rethrow($exception);
        }
    }
    public function household($householdId)
    {
        $statement = $this->pdo->prepare('SELECT id, name, owner_user_id, invite_code_hash FROM jarvis_households WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $householdId]);
        return $this->householdRow($statement->fetch());
    }
    public function members($householdId)
    {
        $statement = $this->pdo->prepare('SELECT hm.household_id, hm.user_id, hm.role, u.nickname, u.avatar_url ' . 'FROM jarvis_household_members hm JOIN jarvis_users u ON u.id = hm.user_id ' . 'WHERE hm.household_id = :household_id ORDER BY (hm.role = \'owner\') DESC, hm.joined_at, hm.user_id');
        $statement->execute(['household_id' => $householdId]);
        $members = [];
        while (($row = $statement->fetch()) !== false) {
            $members[] = $this->memberRow($row);
        }
        return $members;
    }
    public function member($householdId, $userId)
    {
        $statement = $this->pdo->prepare('SELECT hm.household_id, hm.user_id, hm.role, u.nickname, u.avatar_url ' . 'FROM jarvis_household_members hm JOIN jarvis_users u ON u.id = hm.user_id ' . 'WHERE hm.household_id = :household_id AND hm.user_id = :user_id LIMIT 1');
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId]);
        return $this->memberRow($statement->fetch());
    }
    public function replaceInviteHash($householdId, $inviteHash)
    {
        try {
            $statement = $this->pdo->prepare('UPDATE jarvis_households SET invite_code_hash = :hash, updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id');
            $statement->execute(['hash' => $inviteHash, 'id' => $householdId]);
        } catch (PDOException $exception) {
            PdoConstraintMapper::rethrow($exception);
        }
    }
    public function removeMember($householdId, $userId)
    {
        $memberReferences = ['UPDATE jarvis_shopping_list_items SET checked_household_id = NULL, checked_by = NULL ' . 'WHERE household_id = :scope_household_id AND checked_household_id = :member_household_id AND checked_by = :user_id', 'UPDATE jarvis_shopping_list_items SET stocked_household_id = NULL, stocked_by = NULL ' . 'WHERE household_id = :scope_household_id AND stocked_household_id = :member_household_id AND stocked_by = :user_id', 'UPDATE jarvis_tasks SET assigned_household_id = NULL, assigned_to = NULL ' . 'WHERE household_id = :scope_household_id AND assigned_household_id = :member_household_id AND assigned_to = :user_id'];
        foreach ($memberReferences as $sql) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute(['scope_household_id' => $householdId, 'member_household_id' => $householdId, 'user_id' => $userId]);
        }
        $statement = $this->pdo->prepare("DELETE FROM jarvis_household_members WHERE household_id = :household_id AND user_id = :user_id AND role <> 'owner'");
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId]);
        return $statement->rowCount() === 1;
    }
    public function deleteHousehold($householdId)
    {
        $lock = $this->pdo->prepare('SELECT id FROM jarvis_households WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $householdId]);
        if ($lock->fetchColumn() === false) return false;
        $tables = ['jarvis_notification_jobs', 'jarvis_notification_grants', 'jarvis_notification_preferences',
            'jarvis_link_previews', 'jarvis_shopping_list_items', 'jarvis_shopping_lists', 'jarvis_tasks',
            'jarvis_meal_plan_entries', 'jarvis_recipe_ingredients', 'jarvis_recipe_links',
            'jarvis_inventory_movements', 'jarvis_inventory_batches', 'jarvis_recipes',
            'jarvis_ingredients', 'jarvis_household_members'];
        foreach ($tables as $table) {
            $statement = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE household_id = :household_id');
            $statement->execute(['household_id' => $householdId]);
        }
        $statement = $this->pdo->prepare('DELETE FROM jarvis_households WHERE id = :id');
        $statement->execute(['id' => $householdId]);
        return $statement->rowCount() === 1;
    }
    /** @return array{id:int,name:string,owner_user_id:int,invite_code_hash:string}|null */
    private function householdRow($row)
    {
        if ($row === false) {
            return null;
        }
        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'owner_user_id' => (int) $row['owner_user_id'], 'invite_code_hash' => (string) $row['invite_code_hash']];
    }
    /** @return array{household_id:int,user_id:int,role:string,nickname:?string,avatar_url:?string}|null */
    private function memberRow($row)
    {
        if ($row === false) {
            return null;
        }
        return ['household_id' => (int) $row['household_id'], 'user_id' => (int) $row['user_id'], 'role' => (string) $row['role'], 'nickname' => $row['nickname'] === null ? null : (string) $row['nickname'], 'avatar_url' => $row['avatar_url'] === null ? null : (string) $row['avatar_url']];
    }
}
