<?php

namespace App\Auth;

interface WeChatGateway
{
    public function exchangeCode($code);
}
