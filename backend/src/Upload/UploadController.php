<?php

declare(strict_types=1);

namespace App\Upload;

use App\Auth\AuthContext;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;

final class UploadController
{
    public function __construct(private readonly UploadService $uploads, private readonly TenantGuard $guard)
    {
    }

    public function image(Request $request): Response
    {
        $context = $request->attribute('auth');
        if (!$context instanceof AuthContext) throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        $this->guard->requireMembership($context);
        $file = $request->file('file');
        if ($file === null) throw new ApiException(422, 'UPLOAD_INVALID', 'Multipart field file is required.');
        return Response::success($this->uploads->store($file), 201);
    }
}
