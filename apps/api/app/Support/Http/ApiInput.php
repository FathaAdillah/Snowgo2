<?php

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ApiInput
{
    public const PAGE = ['page' => 'sometimes|integer|min:1|max:2147483647', 'per_page' => 'sometimes|integer|min:1|max:100'];

    /** @param array<string,mixed> $data */
    public static function fields(Request $request, array $data): void
    {
        if (array_diff_key($request->all(), $data) || ($request->isMethod('PATCH') && ! $data)
            || (! $request->isMethod('GET') && $request->query->count() > 0)) {
            throw ValidationException::withMessages(['fields' => 'Use only documented fields; PATCH requires at least one.']);
        }
        foreach ($data as $name => $value) {
            if (is_string($value) && str_contains($value, "\0")) {
                throw ValidationException::withMessages([$name => 'Invalid text.']);
            }
        }
    }
}
