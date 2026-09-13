<?php

declare(strict_types=1);

namespace App\Auth;

final class WeChatIdentity
{
    public function __construct(public readonly string $openId)
    {
    }
}
