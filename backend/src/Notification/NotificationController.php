<?php

declare(strict_types=1);

namespace App\Notification;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;

final class NotificationController
{
    public function __construct(private readonly ReminderService $reminders)
    {
    }

    public function preferences(Request $request): Response
    {
        return Response::success($this->reminders->preferences($this->context($request)));
    }

    public function updatePreferences(Request $request): Response
    {
        return Response::success($this->reminders->updatePreferences($this->context($request), $request->json()));
    }

    public function grant(Request $request): Response
    {
        return Response::success($this->reminders->recordGrant($this->context($request), $request->json()), 201);
    }

    private function context(Request $request): AuthContext
    {
        $context = $request->attribute('auth');
        if (!$context instanceof AuthContext) throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        return $context;
    }
}
