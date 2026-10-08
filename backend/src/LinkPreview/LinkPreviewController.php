<?php

namespace App\LinkPreview;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
final class LinkPreviewController
{
    private $previews;
    public function __construct(LinkPreviewService $previews)
    {
        $this->previews = $previews;
    }
    public function create(Request $request)
    {
        $url = isset($request->json()['url']) ? $request->json()['url'] : null;
        if (!is_string($url)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'URL is required.', ['url' => 'Required.']);
        }
        return Response::success($this->previews->preview($this->context($request), $url));
    }
    public function adopt(Request $request)
    {
        $token = $request->routeParam('token');
        if ($token === null) {
            throw new ApiException(404, 'LINK_PREVIEW_NOT_FOUND', 'The link preview was not found.');
        }
        return Response::success($this->previews->adopt($this->context($request), $token));
    }
    public function image(Request $request)
    {
        $token = $request->routeParam('token');
        if ($token === null) {
            throw new ApiException(404, 'LINK_PREVIEW_NOT_FOUND', 'The link preview was not found.');
        }
        $image = $this->previews->image($this->context($request), $token);
        return Response::binary($image['bytes'], $image['mime_type'], $image['max_age']);
    }
    private function context(Request $request)
    {
        $context = $request->attribute('auth');
        if (!$context instanceof AuthContext) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        }
        return $context;
    }
}
