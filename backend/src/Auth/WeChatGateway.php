<?php

declare(strict_types=1);

namespace App\Auth;

interface WeChatGateway
{
    public function exchangeCode(string $code): WeChatIdentity;
}
