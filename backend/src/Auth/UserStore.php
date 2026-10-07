<?php

namespace App\Auth;

interface UserStore
{
    /** @return array{id:int,openid:string,nickname:?string,avatar_url:?string} */
    public function upsertByOpenId($openId, $nickname, $avatarUrl);
}
