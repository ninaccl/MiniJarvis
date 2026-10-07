<?php

namespace App\Auth;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
final class AuthController
{
    private $auth;
    public function __construct(AuthService $auth)
    {
        $this->auth = $auth;
    }
    public function wechat(Request $request)
    {
        $body = $request->json();
        if (!isset($body['code']) || !is_string($body['code'])) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Authentication code is required.', ['code' => 'Required.']);
        }
        foreach (['nickname', 'avatar_url'] as $optional) {
            if (array_key_exists($optional, $body) && $body[$optional] !== null && !is_string($body[$optional])) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Authentication profile is invalid.', [$optional => 'Must be a string.']);
            }
        }
        return Response::success($this->auth->login($body['code'], isset($body['nickname']) ? $body['nickname'] : null, isset($body['avatar_url']) ? $body['avatar_url'] : null));
    }
}
