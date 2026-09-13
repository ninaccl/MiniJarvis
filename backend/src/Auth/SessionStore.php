<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;

interface SessionStore
{
    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void;

    public function findActiveContext(string $tokenHash, DateTimeImmutable $now): ?AuthContext;
}
