<?php

namespace App\Auth;

final class WeChatIdentity
{
    public $openId;
    public function __construct($openId)
    {
        $this->openId = $openId;
    }
}
