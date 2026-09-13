<?php

declare(strict_types=1);

namespace App\LinkPreview;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;

final class LinkPreviewController
{
    public function __construct(private readonly LinkPreviewService $previews)
    {
    }

    public function create(Request $request): Response
    {
        $url = $request->json()['url'] ?? null;
        if (!is_string($url)) throw new ApiException(422, 'VALIDATION_FAILED', 'URL is required.', ['url' => 'Required.']);
        return Response::success($this->previews->preview($this->context($request), $url));
    }

    public function adopt(Request $request): Response
    {
        $token = $request->routeParam('token');
        if ($token === null) throw new ApiException(404, 'LINK_PREVIEW_NOT_FOUND', 'The link preview was not found.');
        return Response::success($this->previews->adopt($this->context($request), $token));
    }

    public function image(Request $request): Response
    {
        $token = $request->routeParam('token');
        if ($token === null) throw new ApiException(404, 'LINK_PREVIEW_NOT_FOUND', 'The link preview was not found.');
        $image = $this->previews->image($this->context($request), $token);
        return Response::binary($image['bytes'], $image['mime_type'], $image['max_age']);
    }

    private function context(Request $request): AuthContext
    {
        $context = $request->attribute('auth');
        if (!$context instanceof AuthContext) throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        return $context;
    }
}
