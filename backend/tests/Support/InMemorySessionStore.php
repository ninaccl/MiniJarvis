<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\AuthContext;
use App\Auth\SessionStore;
use DateTimeImmutable;

final class InMemorySessionStore implements SessionStore
{
    /** @var list<array{user_id:int,token_hash:string,expires_at:DateTimeImmutable}> */
    public array $sessions = [];

    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $this->sessions[] = [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ];
    }

    public function findActiveContext(string $tokenHash, DateTimeImmutable $now): ?AuthContext
    {
        foreach ($this->sessions as $session) {
            if (hash_equals($session['token_hash'], $tokenHash) && $session['expires_at'] > $now) {
                return new AuthContext($session['user_id'], null, null);
            }
        }

        return null;
    }
}
