<?php

namespace App\Http;

use Exception;
final class ApiKernel
{
    private $router;
    public function __construct(Router $router)
    {
        $this->router = $router;
    }
    public function handle(Request $request)
    {
        try {
            return $this->router->dispatch($request);
        } catch (ApiException $exception) {
            return Response::fromException($exception);
        } catch (Exception $exception) {
            error_log(sprintf('%s: %s', get_class($exception), $exception->getMessage()));
            return Response::internalError();
        }
    }
}
