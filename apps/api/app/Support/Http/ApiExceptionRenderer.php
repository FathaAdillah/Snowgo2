<?php

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps framework exceptions to the shared error codes in docs/05-API-CONTRACT.md.
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => ApiResponse::error('VALIDATION_FAILED', 'Validation failed', 422, $e->errors()),
            $e instanceof AuthenticationException => ApiResponse::error('UNAUTHENTICATED', 'Unauthenticated', 401),
            ($e instanceof AuthorizationException ? $e : $e->getPrevious())?->getCode() === 'EMAIL_NOT_VERIFIED' => ApiResponse::error('EMAIL_NOT_VERIFIED', 'Email address is not verified', 403),
            $e instanceof AuthorizationException, $e instanceof HttpExceptionInterface && $e->getStatusCode() === 403 => ApiResponse::error('FORBIDDEN', 'Forbidden', 403),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error('NOT_FOUND', 'Not found', 404),
            $e instanceof TokenMismatchException => ApiResponse::error('CSRF_TOKEN_MISMATCH', 'CSRF token mismatch', 419),
            $e instanceof ThrottleRequestsException => ApiResponse::error('THROTTLED', 'Too many requests', 429, null, [], $this->retryAfter($e)),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => ApiResponse::error('CSRF_TOKEN_MISMATCH', 'CSRF token mismatch', 419),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => null,
            default => config('app.debug') ? null : ApiResponse::error('INTERNAL_ERROR', 'Internal server error', 500),
        };
    }

    /**
     * @return array<string, string>
     */
    private function retryAfter(ThrottleRequestsException $e): array
    {
        $value = $e->getHeaders()['Retry-After'] ?? null;

        return $value === null ? [] : ['Retry-After' => (string) $value];
    }
}
