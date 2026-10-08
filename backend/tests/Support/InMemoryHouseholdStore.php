<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Household\InviteCodeCollision;
use App\Household\HouseholdStore;
use App\Household\MembershipAlreadyExists;
use RuntimeException;

final class InMemoryHouseholdStore implements HouseholdStore
{
    public int $createInviteCollisionsRemaining = 0;
    public int $resetInviteCollisionsRemaining = 0;
    public bool $createMembershipConflict = false;
    public bool $joinMembershipConflict = false;
    public ?RuntimeException $createFailure = null;
    public int $createAttempts = 0;
    public int $resetAttempts = 0;
    /** @var array<int, array{id:int,name:string,owner_user_id:int,invite_code_hash:string}> */
    private array $households = [];
    /** @var array<int, array{household_id:int,user_id:int,role:string,nickname:?string,avatar_url:?string}> */
    private array $memberships = [];

    public function membershipForUser(int $userId): ?array
    {
        foreach ($this->memberships as $member) {
            if ($member['user_id'] === $userId) return $member;
        }
        return null;
    }

    public function householdsForUser(int $userId): array
    {
        $result = [];
        foreach ($this->memberships as $member) {
            if ($member['user_id'] === $userId) $result[] = [...$this->households[$member['household_id']], 'role' => $member['role']];
        }
        return $result;
    }

    public function inviteHashExists(string $inviteHash): bool
    {
        foreach ($this->households as $household) {
            if (hash_equals($household['invite_code_hash'], $inviteHash)) {
                return true;
            }
        }

        return false;
    }

    public function create(string $name, int $ownerUserId, string $inviteHash): int
    {
        $this->createAttempts++;
        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }
        if ($this->createMembershipConflict) {
            throw new MembershipAlreadyExists();
        }
        if ($this->createInviteCollisionsRemaining > 0) {
            $this->createInviteCollisionsRemaining--;
            throw new InviteCodeCollision();
        }
        $id = $this->households === [] ? 1 : max(array_keys($this->households)) + 1;
        $this->households[$id] = [
            'id' => $id,
            'name' => $name,
            'owner_user_id' => $ownerUserId,
            'invite_code_hash' => $inviteHash,
        ];
        $this->memberships[$id . ':' . $ownerUserId] = [
            'household_id' => $id,
            'user_id' => $ownerUserId,
            'role' => 'owner',
            'nickname' => 'Owner',
            'avatar_url' => null,
        ];
        return $id;
    }

    public function householdByInviteHash(string $inviteHash): ?array
    {
        foreach ($this->households as $household) {
            if (hash_equals($household['invite_code_hash'], $inviteHash)) {
                return $household;
            }
        }

        return null;
    }

    public function addMember(int $householdId, int $userId): void
    {
        if ($this->joinMembershipConflict) {
            throw new MembershipAlreadyExists();
        }
        if (isset($this->memberships[$householdId . ':' . $userId])) throw new MembershipAlreadyExists();
        $this->memberships[$householdId . ':' . $userId] = [
            'household_id' => $householdId,
            'user_id' => $userId,
            'role' => 'member',
            'nickname' => 'Member ' . $userId,
            'avatar_url' => null,
        ];
    }

    public function household(int $householdId): ?array
    {
        return $this->households[$householdId] ?? null;
    }

    public function members(int $householdId): array
    {
        return array_values(array_filter(
            $this->memberships,
            static fn (array $member): bool => $member['household_id'] === $householdId,
        ));
    }

    public function member(int $householdId, int $userId): ?array
    {
        return $this->memberships[$householdId . ':' . $userId] ?? null;
    }

    public function replaceInviteHash(int $householdId, string $inviteHash): void
    {
        $this->resetAttempts++;
        if ($this->resetInviteCollisionsRemaining > 0) {
            $this->resetInviteCollisionsRemaining--;
            throw new InviteCodeCollision();
        }
        $this->households[$householdId]['invite_code_hash'] = $inviteHash;
    }

    public function removeMember(int $householdId, int $userId): bool
    {
        $key = $householdId . ':' . $userId;
        if (!isset($this->memberships[$key]) || $this->memberships[$key]['role'] === 'owner') {
            return false;
        }

        unset($this->memberships[$key]);
        return true;
    }

    public function deleteHousehold(int $householdId): bool
    {
        if (!isset($this->households[$householdId])) return false;
        unset($this->households[$householdId]);
        foreach ($this->memberships as $key => $member) {
            if ($member['household_id'] === $householdId) unset($this->memberships[$key]);
        }
        return true;
    }
}
