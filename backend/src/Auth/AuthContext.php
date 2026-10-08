<?php

namespace App\Auth;

final class AuthContext
{
    public $userId;
    public $householdId;
    public $role;
    public function __construct($userId, $householdId, $role)
    {
        $this->userId = $userId;
        $this->householdId = $householdId;
        $this->role = $role;
    }
}
