<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;

final class WeChatApiClient implements WeChatGateway
{
    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
    ) {
    }

    public function exchangeCode(string $code): WeChatIdentity
    {
        if ($this->appId === '' || $this->appSecret === '') {
            throw new ApiException(500, 'CONFIGURATION_ERROR', 'WeChat authentication is not configured.');
        }

        $url = 'https://api.weixin.qq.com/sns/jscode2session?' . http_build_query([
            'appid' => $this->appId,
            'secret' => $this->appSecret,
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ]);
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ApiException(500, 'AUTH_PROVIDER_UNAVAILABLE', 'WeChat authentication is temporarily unavailable.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($raw) || $httpStatus < 200 || $httpStatus >= 300) {
            throw new ApiException(500, 'AUTH_PROVIDER_UNAVAILABLE', 'WeChat authentication is temporarily unavailable.');
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(500, 'AUTH_PROVIDER_UNAVAILABLE', 'WeChat authentication returned an invalid response.');
        }
        if (!is_array($payload) || !isset($payload['openid']) || !is_string($payload['openid']) || $payload['openid'] === '') {
            throw new ApiException(422, 'AUTH_INVALID_CODE', 'The authentication code is invalid.');
        }

        // session_key is deliberately ignored: it is never persisted or returned.
        return new WeChatIdentity($payload['openid']);
    }
}
