<?php

declare(strict_types=1);

namespace App\Household;

use PDO;
use PDOException;

final class PdoHouseholdStore implements HouseholdStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function membershipForUser(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT hm.household_id, hm.user_id, hm.role, u.nickname, u.avatar_url '
            . 'FROM household_members hm JOIN users u ON u.id = hm.user_id WHERE hm.user_id = :user_id LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        return $this->memberRow($statement->fetch());
    }

    public function inviteHashExists(string $inviteHash): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM households WHERE invite_code_hash = :hash LIMIT 1');
        $statement->execute(['hash' => $inviteHash]);
        return $statement->fetchColumn() !== false;
    }

    public function create(string $name, int $ownerUserId, string $inviteHash): int
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO households (name, owner_user_id, invite_code_hash) VALUES (:name, :owner, :hash)'
            );
            $statement->execute(['name' => $name, 'owner' => $ownerUserId, 'hash' => $inviteHash]);
            $householdId = (int) $this->pdo->lastInsertId();
            $member = $this->pdo->prepare(
                "INSERT INTO household_members (household_id, user_id, role) VALUES (:household_id, :user_id, 'owner')"
            );
            $member->execute(['household_id' => $householdId, 'user_id' => $ownerUserId]);
            return $householdId;
        } catch (PDOException $exception) {
            PdoConstraintMapper::rethrow($exception);
        }
    }

    public function householdByInviteHash(string $inviteHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, owner_user_id, invite_code_hash FROM households WHERE invite_code_hash = :hash LIMIT 1'
        );
        $statement->execute(['hash' => $inviteHash]);
        return $this->householdRow($statement->fetch());
    }

    public function addMember(int $householdId, int $userId): void
    {
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO household_members (household_id, user_id, role) VALUES (:household_id, :user_id, 'member')"
            );
            $statement->execute(['household_id' => $householdId, 'user_id' => $userId]);
        } catch (PDOException $exception) {
            PdoConstraintMapper::rethrow($exception);
        }
    }

    public function household(int $householdId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, owner_user_id, invite_code_hash FROM households WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $householdId]);
        return $this->householdRow($statement->fetch());
    }

    public function members(int $householdId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT hm.household_id, hm.user_id, hm.role, u.nickname, u.avatar_url '
            . 'FROM household_members hm JOIN users u ON u.id = hm.user_id '
            . 'WHERE hm.household_id = :household_id ORDER BY (hm.role = \'owner\') DESC, hm.joined_at, hm.user_id'
        );
        $statement->execute(['household_id' => $householdId]);
        $members = [];
        while (($row = $statement->fetch()) !== false) {
            $members[] = $this->memberRow($row);
        }
        return $members;
    }

    public function member(int $householdId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT hm.household_id, hm.user_id, hm.role, u.nickname, u.avatar_url '
            . 'FROM household_members hm JOIN users u ON u.id = hm.user_id '
            . 'WHERE hm.household_id = :household_id AND hm.user_id = :user_id LIMIT 1'
        );
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId]);
        return $this->memberRow($statement->fetch());
    }

    public function replaceInviteHash(int $householdId, string $inviteHash): void
    {
        try {
            $statement = $this->pdo->prepare(
                'UPDATE households SET invite_code_hash = :hash, updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id'
            );
            $statement->execute(['hash' => $inviteHash, 'id' => $householdId]);
        } catch (PDOException $exception) {
            PdoConstraintMapper::rethrow($exception);
        }
    }

    public function removeMember(int $householdId, int $userId): bool
    {
        $memberReferences = [
            'UPDATE shopping_list_items SET checked_household_id = NULL, checked_by = NULL '
                . 'WHERE household_id = :scope_household_id AND checked_household_id = :member_household_id AND checked_by = :user_id',
            'UPDATE shopping_list_items SET stocked_household_id = NULL, stocked_by = NULL '
                . 'WHERE household_id = :scope_household_id AND stocked_household_id = :member_household_id AND stocked_by = :user_id',
            'UPDATE tasks SET assigned_household_id = NULL, assigned_to = NULL '
                . 'WHERE household_id = :scope_household_id AND assigned_household_id = :member_household_id AND assigned_to = :user_id',
        ];
        foreach ($memberReferences as $sql) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([
                'scope_household_id' => $householdId,
                'member_household_id' => $householdId,
                'user_id' => $userId,
            ]);
        }

        $statement = $this->pdo->prepare(
            "DELETE FROM household_members WHERE household_id = :household_id AND user_id = :user_id AND role <> 'owner'"
        );
        $statement->execute(['household_id' => $householdId, 'user_id' => $userId]);
        return $statement->rowCount() === 1;
    }

    /** @return array{id:int,name:string,owner_user_id:int,invite_code_hash:string}|null */
    private function householdRow(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'owner_user_id' => (int) $row['owner_user_id'],
            'invite_code_hash' => (string) $row['invite_code_hash'],
        ];
    }

    /** @return array{household_id:int,user_id:int,role:string,nickname:?string,avatar_url:?string}|null */
    private function memberRow(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        return [
            'household_id' => (int) $row['household_id'],
            'user_id' => (int) $row['user_id'],
            'role' => (string) $row['role'],
            'nickname' => $row['nickname'] === null ? null : (string) $row['nickname'],
            'avatar_url' => $row['avatar_url'] === null ? null : (string) $row['avatar_url'],
        ];
    }
}
