<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Http\ApiException;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    public function testSuccessResponseUsesStableEnvelope(): void
    {
        $response = Response::success(['status' => 'ok'], 200, ['request_id' => 'r1']);

        self::assertSame(200, $response->status());
        self::assertSame([
            'success' => true,
            'data' => ['status' => 'ok'],
            'meta' => ['request_id' => 'r1'],
        ], $response->payload());
    }

    public function testDomainFailureMapsToStableEnvelope(): void
    {
        $router = new Router();
        $router->add('POST', '/api/v1/example', static function (): never {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Input is invalid.', ['name' => 'Required.']);
        });
        $kernel = new ApiKernel($router);

        $response = $kernel->handle(new Request('POST', '/api/v1/example'));

        self::assertSame(422, $response->status());
        self::assertSame([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'Input is invalid.',
                'fields' => ['name' => 'Required.'],
            ],
        ], $response->payload());
    }
}
