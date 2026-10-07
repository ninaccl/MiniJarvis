<?php

namespace App\Auth;

use DateTimeImmutable;
interface SessionStore
{
    public function create($userId, $tokenHash, DateTimeImmutable $expiresAt);
    public function findActiveContext($tokenHash, DateTimeImmutable $now);
}
