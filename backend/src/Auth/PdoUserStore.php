<?php

namespace App\Auth;

use PDO;
final class PdoUserStore implements UserStore
{
    private $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function upsertByOpenId($openId, $nickname, $avatarUrl)
    {
        $statement = $this->pdo->prepare('INSERT INTO jarvis_users (openid, nickname, avatar_url) VALUES (:openid, :nickname, :avatar_url) ' . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), ' . 'nickname = COALESCE(VALUES(nickname), nickname), avatar_url = COALESCE(VALUES(avatar_url), avatar_url), ' . 'updated_at = CURRENT_TIMESTAMP(6)');
        $statement->execute(['openid' => $openId, 'nickname' => $nickname, 'avatar_url' => $avatarUrl]);
        $id = (int) $this->pdo->lastInsertId();
        $query = $this->pdo->prepare('SELECT id, openid, nickname, avatar_url FROM jarvis_users WHERE id = :id');
        $query->execute(['id' => $id]);
        /** @var array{id:int|string,openid:string,nickname:?string,avatar_url:?string} $user */
        $user = $query->fetch();
        $user['id'] = (int) $user['id'];
        return $user;
    }
}
