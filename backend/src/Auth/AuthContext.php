<?php

declare(strict_types=1);

namespace App\Auth;

final class AuthContext
{
    public function __construct(
        public readonly int $userId,
        public readonly ?int $householdId,
        public readonly ?string $role,
    ) {
    }
}
