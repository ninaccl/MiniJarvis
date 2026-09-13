<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;

final class AuthMiddleware
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $authorization = $request->header('authorization');
        if ($authorization === null || preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches) !== 1) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A Bearer token is required.');
        }
        $context = $this->auth->authenticateToken($matches[1]);
        $response = $next($request->withAttribute('auth', $context));
        if (!$response instanceof Response) {
            throw new \LogicException('Authenticated handlers must return an HTTP response.');
        }
        return $response;
    }
}
