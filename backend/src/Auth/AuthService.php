<?php

namespace App\Auth;

use App\Http\ApiException;
use App\Support\Text;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
final class AuthService
{
    private $environment;
    private $users;
    private $sessions;
    private $weChat;
    public function __construct($environment, UserStore $users, SessionStore $sessions, WeChatGateway $weChat)
    {
        $this->environment = $environment;
        $this->users = $users;
        $this->sessions = $sessions;
        $this->weChat = $weChat;
    }
    /** @return array{access_token:string,token_type:string,expires_at:string,user:array{id:int,openid:string,nickname:?string,avatar_url:?string}} */
    public function login($code, $nickname, $avatarUrl)
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
        if (\App\Support\Compat::startsWith($code, 'dev:')) {
            if ($this->environment !== 'local' || preg_match('/^dev:[A-Za-z0-9._-]{1,124}$/', $code) !== 1) {
                throw new ApiException(422, 'AUTH_INVALID_CODE', 'The authentication code is invalid.');
            }
            $openId = $code;
        } else {
            $openId = $this->weChat->exchangeCode($code)->openId;
        }
        $user = $this->users->upsertByOpenId($openId, $nickname, $avatarUrl);
        $plainToken = bin2hex(\App\Support\Compat::randomBytes(32));
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('P30D'));
        $this->sessions->create($user['id'], hash('sha256', $plainToken), $expiresAt);
        return ['access_token' => $plainToken, 'token_type' => 'Bearer', 'expires_at' => $expiresAt->format(DATE_ATOM), 'user' => $user];
    }
    public function authenticateToken($plainToken, $householdId = null)
    {
        if (preg_match('/^[a-f0-9]{64}$/', $plainToken) !== 1) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        }
        $context = $this->sessions->findActiveContext(hash('sha256', $plainToken), new DateTimeImmutable('now', new DateTimeZone('UTC')), $householdId);
        if ($context === null) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        }
        if ($householdId !== null && $context->householdId !== $householdId) {
            throw new ApiException(403, 'HOUSEHOLD_MEMBERSHIP_REQUIRED', 'Household membership is required.');
        }
        return $context;
    }
}
