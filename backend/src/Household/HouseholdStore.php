<?php

declare(strict_types=1);

namespace App\Household;

interface HouseholdStore
{
    /** @return array{household_id:int,user_id:int,role:string,nickname:?string,avatar_url:?string}|null */
    public function membershipForUser(int $userId): ?array;

    public function inviteHashExists(string $inviteHash): bool;

    public function create(string $name, int $ownerUserId, string $inviteHash): int;

    /** @return array{id:int,name:string,owner_user_id:int,invite_code_hash:string}|null */
    public function householdByInviteHash(string $inviteHash): ?array;

    public function addMember(int $householdId, int $userId): void;

    /** @return array{id:int,name:string,owner_user_id:int,invite_code_hash:string}|null */
    public function household(int $householdId): ?array;

    /** @return list<array{household_id:int,user_id:int,role:string,nickname:?string,avatar_url:?string}> */
    public function members(int $householdId): array;

    /** @return array{household_id:int,user_id:int,role:string,nickname:?string,avatar_url:?string}|null */
    public function member(int $householdId, int $userId): ?array;

    public function replaceInviteHash(int $householdId, string $inviteHash): void;

    public function removeMember(int $householdId, int $userId): bool;
}
