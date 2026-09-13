<?php

declare(strict_types=1);

namespace App\Http;

use Throwable;

final class ApiKernel
{
    public function __construct(private readonly Router $router)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (ApiException $exception) {
            return Response::fromException($exception);
        } catch (Throwable $exception) {
            error_log(sprintf('%s: %s', $exception::class, $exception->getMessage()));
            return Response::internalError();
        }
    }
}
