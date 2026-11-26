<?php

namespace App\Modules\Shipping;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CourierFailure extends HttpException
{
    public function __construct(public readonly ?int $upstreamStatus, string $message)
    {
        // An upstream credential error must never log the customer or operator out.
        parent::__construct(503, $message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['is_success' => false, 'message' => $this->getMessage()], $this->getStatusCode());
    }
}
