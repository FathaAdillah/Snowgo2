<?php

namespace App\Modules\Foundation\Idempotency;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

final class RequestFingerprint
{
    public static function hash(Request $request): string
    {
        try {
            $body = $request->isJson() && $request->getContent() !== ''
                ? json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR)
                : $request->request->all();
        } catch (JsonException) {
            throw ValidationException::withMessages(['body' => 'Invalid JSON.']);
        }

        return hash('sha256', json_encode(self::canonical([
            $request->getPathInfo(), $request->query->all(), $body, $request->allFiles(),
        ]), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            if (! $value->isValid() || ($hash = hash_file('sha256', $value->getPathname())) === false) {
                throw ValidationException::withMessages(['file' => 'Unreadable upload.']);
            }

            return [$value->getClientOriginalName(), $hash];
        }
        if ($value instanceof stdClass || (is_array($value) && ! array_is_list($value))) {
            $fields = (array) $value;
            ksort($fields, SORT_STRING);

            return (object) array_map(self::canonical(...), $fields);
        }

        return is_array($value) ? array_map(self::canonical(...), $value) : $value;
    }
}
