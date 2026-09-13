<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\WeChatGateway;
use App\Auth\WeChatIdentity;

final class FakeWeChatGateway implements WeChatGateway
{
    public function __construct(private readonly string $openId = 'wx-open-id')
    {
    }

    public function exchangeCode(string $code): WeChatIdentity
    {
        return new WeChatIdentity($this->openId);
    }
}
