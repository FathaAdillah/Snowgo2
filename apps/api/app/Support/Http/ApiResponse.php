<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;

/**
 * Builds the shared response envelope defined in docs/05-API-CONTRACT.md.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200, array $meta = []): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => self::meta($meta),
        ], $status);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function error(string $code, string $message, int $status, ?array $errors = null, array $meta = [], array $headers = []): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $message,
            'code' => $code,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        $body['meta'] = self::meta($meta);

        return new JsonResponse($body, $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function meta(array $meta): array
    {
        return ['request_id' => RequestId::current()] + $meta;
    }
}
