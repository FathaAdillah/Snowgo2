<?php

namespace App\Modules\Foundation\Files;

use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FileProblem extends HttpException
{
    public function __construct(int $status, private readonly string $errorCode, string $message)
    {
        parent::__construct($status, $message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), $this->getStatusCode());
    }
}
