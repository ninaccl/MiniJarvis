<?php

namespace App\Upload;

use App\Auth\AuthContext;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
final class UploadController
{
    private $uploads;
    private $guard;
    public function __construct(UploadService $uploads, TenantGuard $guard)
    {
        $this->uploads = $uploads;
        $this->guard = $guard;
    }
    public function image(Request $request)
    {
        $context = $request->attribute('auth');
        if (!$context instanceof AuthContext) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        }
        $this->guard->requireMembership($context);
        $file = $request->file('file');
        if ($file === null) {
            throw new ApiException(422, 'UPLOAD_INVALID', 'Multipart field file is required.');
        }
        return Response::success($this->uploads->store($file), 201);
    }
}
