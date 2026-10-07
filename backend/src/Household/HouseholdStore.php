<?php

namespace App\Household;

interface HouseholdStore
{
    /** @return array{household_id:int,user_id:int,role:string,nickname:?string,avatar_url:?string}|null */
    public function membershipForUser($userId);
    public function inviteHashExists($inviteHash);
    public function create($name, $ownerUserId, $inviteHash);
    /** @return array{id:int,name:string,owner_user_id:int,invite_code_hash:string}|null */
    public function householdByInviteHash($inviteHash);
    public function addMember($householdId, $userId);
    /** @return array{id:int,name:string,owner_user_id:int,invite_code_hash:string}|null */
    public function household($householdId);
    /** @return list<array{household_id:int,user_id:int,role:string,nickname:?string,avatar_url:?string}> */
    public function members($householdId);
    /** @return array{household_id:int,user_id:int,role:string,nickname:?string,avatar_url:?string}|null */
    public function member($householdId, $userId);
    public function replaceInviteHash($householdId, $inviteHash);
    public function removeMember($householdId, $userId);
}
