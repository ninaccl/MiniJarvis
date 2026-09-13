<?php

declare(strict_types=1);

namespace App\Auth;

interface UserStore
{
    /** @return array{id:int,openid:string,nickname:?string,avatar_url:?string} */
    public function upsertByOpenId(string $openId, ?string $nickname, ?string $avatarUrl): array;
}
