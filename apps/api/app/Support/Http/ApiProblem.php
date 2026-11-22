<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A domain error rendered with a contract error code (docs/05-API-CONTRACT.md).
 */
final class ApiProblem extends HttpException
{
    public function __construct(int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($status, $message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), $this->getStatusCode());
    }
}
