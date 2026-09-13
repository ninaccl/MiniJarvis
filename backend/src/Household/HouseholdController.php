<?php

declare(strict_types=1);

namespace App\Household;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;

final class HouseholdController
{
    public function __construct(private readonly HouseholdService $households)
    {
    }

    public function create(Request $request): Response
    {
        $name = $request->json()['name'] ?? null;
        if (!is_string($name)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Household name is required.', ['name' => 'Required.']);
        }
        return Response::success($this->households->create($this->context($request), $name), 201);
    }

    public function join(Request $request): Response
    {
        $inviteCode = $request->json()['invite_code'] ?? null;
        if (!is_string($inviteCode)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Invite code is required.', ['invite_code' => 'Required.']);
        }
        return Response::success($this->households->join($this->context($request), $inviteCode));
    }

    public function current(Request $request): Response
    {
        return Response::success($this->households->current($this->context($request)));
    }

    public function resetInvite(Request $request): Response
    {
        return Response::success($this->households->resetInvite($this->context($request)));
    }

    public function removeMember(Request $request): Response
    {
        $userId = $request->routeParam('userId');
        if ($userId === null || preg_match('/^[1-9][0-9]*$/', $userId) !== 1) {
            throw new ApiException(404, 'HOUSEHOLD_MEMBER_NOT_FOUND', 'The household member was not found.');
        }
        $this->households->removeMember($this->context($request), (int) $userId);
        return Response::success(['removed' => true]);
    }

    private function context(Request $request): AuthContext
    {
        $context = $request->attribute('auth');
        if (!$context instanceof AuthContext) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        }
        return $context;
    }
}
