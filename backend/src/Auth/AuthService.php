<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;
use App\Support\Text;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class AuthService
{
    public function __construct(
        private readonly string $environment,
        private readonly UserStore $users,
        private readonly SessionStore $sessions,
        private readonly WeChatGateway $weChat,
    ) {
    }

    /** @return array{access_token:string,token_type:string,expires_at:string,user:array{id:int,openid:string,nickname:?string,avatar_url:?string}} */
    public function login(string $code, ?string $nickname, ?string $avatarUrl): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Authentication code is required.', ['code' => 'Required.']);
        }
        if ($nickname !== null && Text::length($nickname) > 255) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Nickname is too long.', ['nickname' => 'Maximum length is 255 characters.']);
        }
        if ($avatarUrl !== null && filter_var($avatarUrl, FILTER_VALIDATE_URL) === false) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Avatar URL is invalid.', ['avatar_url' => 'Must be a valid URL.']);
        }

        if (str_starts_with($code, 'dev:')) {
            if ($this->environment !== 'local' || preg_match('/^dev:[A-Za-z0-9._-]{1,124}$/', $code) !== 1) {
                throw new ApiException(422, 'AUTH_INVALID_CODE', 'The authentication code is invalid.');
            }
            $openId = $code;
        } else {
            $openId = $this->weChat->exchangeCode($code)->openId;
        }

        $user = $this->users->upsertByOpenId($openId, $nickname, $avatarUrl);
        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('P30D'));
        $this->sessions->create($user['id'], hash('sha256', $plainToken), $expiresAt);

        return [
            'access_token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'user' => $user,
        ];
    }

    public function authenticateToken(string $plainToken): AuthContext
    {
        if (preg_match('/^[a-f0-9]{64}$/', $plainToken) !== 1) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        }
        $context = $this->sessions->findActiveContext(
            hash('sha256', $plainToken),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        if ($context === null) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        }
        return $context;
    }
}
