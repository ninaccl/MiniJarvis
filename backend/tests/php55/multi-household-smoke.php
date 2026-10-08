<?php

require dirname(dirname(__DIR__)) . '/bootstrap.php';

class MultiHouseholdTransaction implements App\Database\TransactionManager
{
    public function transaction(callable $callback) { return $callback(); }
}

class MultiHouseholdStore implements App\Household\HouseholdStore
{
    public $homes = array();
    public $members = array();
    public function membershipForUser($userId) { foreach ($this->members as $member) if ($member['user_id'] === $userId) return $member; return null; }
    public function householdsForUser($userId) {
        $result = array();
        foreach ($this->members as $member) if ($member['user_id'] === $userId) $result[] = array_merge($this->homes[$member['household_id']], array('role' => $member['role']));
        return $result;
    }
    public function inviteHashExists($hash) { foreach ($this->homes as $home) if ($home['invite_code_hash'] === $hash) return true; return false; }
    public function create($name, $ownerUserId, $hash) {
        $id = count($this->homes) + 1;
        $this->homes[$id] = array('id' => $id, 'name' => $name, 'owner_user_id' => $ownerUserId, 'invite_code_hash' => $hash);
        $this->members[$id . ':' . $ownerUserId] = array('household_id' => $id, 'user_id' => $ownerUserId, 'role' => 'owner');
        return $id;
    }
    public function householdByInviteHash($hash) { foreach ($this->homes as $home) if ($home['invite_code_hash'] === $hash) return $home; return null; }
    public function addMember($householdId, $userId) {
        $key = $householdId . ':' . $userId;
        if (isset($this->members[$key])) throw new App\Household\MembershipAlreadyExists();
        $this->members[$key] = array('household_id' => $householdId, 'user_id' => $userId, 'role' => 'member');
    }
    public function household($householdId) { return isset($this->homes[$householdId]) ? $this->homes[$householdId] : null; }
    public function members($householdId) { $result = array(); foreach ($this->members as $member) if ($member['household_id'] === $householdId) $result[] = $member; return $result; }
    public function member($householdId, $userId) { $key = $householdId . ':' . $userId; return isset($this->members[$key]) ? $this->members[$key] : null; }
    public function replaceInviteHash($householdId, $hash) { $this->homes[$householdId]['invite_code_hash'] = $hash; }
    public function removeMember($householdId, $userId) {
        $key = $householdId . ':' . $userId;
        if (!isset($this->members[$key]) || $this->members[$key]['role'] === 'owner') return false;
        unset($this->members[$key]); return true;
    }
    public function deleteHousehold($householdId) {
        if (!isset($this->homes[$householdId])) return false;
        unset($this->homes[$householdId]);
        foreach ($this->members as $key => $member) if ($member['household_id'] === $householdId) unset($this->members[$key]);
        return true;
    }
}

function checkMulti($condition, $label) { if (!$condition) throw new RuntimeException($label); }
function expectMultiError($callback, $code) {
    try { $callback(); } catch (App\Http\ApiException $error) { checkMulti($error->errorCode() === $code, $code); return; }
    throw new RuntimeException('Expected ' . $code);
}

$store = new MultiHouseholdStore();
$service = new App\Household\HouseholdService(new MultiHouseholdTransaction(), $store, new App\Household\TenantGuard());
$first = $service->create(new App\Auth\AuthContext(1, null, null), 'First');
$second = $service->create(new App\Auth\AuthContext(3, null, null), 'Second');
$service->join(new App\Auth\AuthContext(2, null, null), $first['invite_code']);
$service->join(new App\Auth\AuthContext(2, $first['household']['id'], 'member'), $second['invite_code']);
checkMulti(count($service->listItems(new App\Auth\AuthContext(2, null, null))['households']) === 2, 'multiple memberships');
expectMultiError(function () use ($service, $second) { $service->join(new App\Auth\AuthContext(2, null, null), $second['invite_code']); }, 'HOUSEHOLD_ALREADY_JOINED');
expectMultiError(function () use ($service, $first) { $service->leave(new App\Auth\AuthContext(1, $first['household']['id'], 'owner')); }, 'HOUSEHOLD_OWNER_CANNOT_LEAVE');
$service->leave(new App\Auth\AuthContext(2, $first['household']['id'], 'member'));
checkMulti(count($service->listItems(new App\Auth\AuthContext(2, null, null))['households']) === 1, 'leave one household');
expectMultiError(function () use ($service, $second) { $service->dissolve(new App\Auth\AuthContext(2, $second['household']['id'], 'member')); }, 'HOUSEHOLD_OWNER_REQUIRED');
$service->dissolve(new App\Auth\AuthContext(3, $second['household']['id'], 'owner'));
checkMulti(count($service->listItems(new App\Auth\AuthContext(2, null, null))['households']) === 0, 'dissolve household');
fwrite(STDOUT, "multi-household smoke passed\n");
