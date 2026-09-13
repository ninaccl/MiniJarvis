<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;
use PDO;

final class PdoSessionStore implements SessionStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO api_sessions (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function findActiveContext(string $tokenHash, DateTimeImmutable $now): ?AuthContext
    {
        $statement = $this->pdo->prepare(
            'SELECT s.user_id, hm.household_id, hm.role '
            . 'FROM api_sessions s LEFT JOIN household_members hm ON hm.user_id = s.user_id '
            . 'WHERE s.token_hash = :token_hash AND s.revoked_at IS NULL AND s.expires_at > :now LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash, 'now' => $now->format('Y-m-d H:i:s.u')]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        return new AuthContext(
            (int) $row['user_id'],
            $row['household_id'] === null ? null : (int) $row['household_id'],
            $row['role'] === null ? null : (string) $row['role'],
        );
    }
}
