<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\UserStore;

final class InMemoryUserStore implements UserStore
{
    /** @var array<int, array{id:int,openid:string,nickname:?string,avatar_url:?string}> */
    private array $users = [];

    public function upsertByOpenId(string $openId, ?string $nickname, ?string $avatarUrl): array
    {
        foreach ($this->users as $id => $user) {
            if ($user['openid'] === $openId) {
                $this->users[$id]['nickname'] = $nickname ?? $user['nickname'];
                $this->users[$id]['avatar_url'] = $avatarUrl ?? $user['avatar_url'];
                return $this->users[$id];
            }
        }

        $id = count($this->users) + 1;
        return $this->users[$id] = [
            'id' => $id,
            'openid' => $openId,
            'nickname' => $nickname,
            'avatar_url' => $avatarUrl,
        ];
    }
}
