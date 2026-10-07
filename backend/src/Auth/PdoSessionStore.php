<?php

namespace App\Auth;

use DateTimeImmutable;
use PDO;
final class PdoSessionStore implements SessionStore
{
    private $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function create($userId, $tokenHash, DateTimeImmutable $expiresAt)
    {
        $statement = $this->pdo->prepare('INSERT INTO jarvis_api_sessions (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)');
        $statement->execute(['user_id' => $userId, 'token_hash' => $tokenHash, 'expires_at' => $expiresAt->format('Y-m-d H:i:s.u')]);
    }
    public function findActiveContext($tokenHash, DateTimeImmutable $now)
    {
        $statement = $this->pdo->prepare('SELECT s.user_id, hm.household_id, hm.role ' . 'FROM jarvis_api_sessions s LEFT JOIN jarvis_household_members hm ON hm.user_id = s.user_id ' . 'WHERE s.token_hash = :token_hash AND s.revoked_at IS NULL AND s.expires_at > :now LIMIT 1');
        $statement->execute(['token_hash' => $tokenHash, 'now' => $now->format('Y-m-d H:i:s.u')]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        return new AuthContext((int) $row['user_id'], $row['household_id'] === null ? null : (int) $row['household_id'], $row['role'] === null ? null : (string) $row['role']);
    }
}
