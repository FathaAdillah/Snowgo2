<?php

namespace App\Support\Http;

use Illuminate\Support\Str;

final class RequestId
{
    public const HEADER = 'X-Request-Id';

    private const ATTRIBUTE = 'request_id';

    public static function current(): string
    {
        $request = request();
        $id = $request->attributes->get(self::ATTRIBUTE);

        if (! is_string($id)) {
            $id = self::resolve($request->headers->get(self::HEADER));
            $request->attributes->set(self::ATTRIBUTE, $id);
        }

        return $id;
    }

    /**
     * Accept an upstream ID (from Nginx) only when it is a safe token.
     */
    public static function resolve(?string $incoming): string
    {
        if ($incoming !== null && preg_match('/\A[A-Za-z0-9\-]{8,128}\z/', $incoming) === 1) {
            return $incoming;
        }

        return (string) Str::uuid();
    }
}
