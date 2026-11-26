<?php

namespace App\Modules\Shipping;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class CourierApi
{
    public static function enabled(): bool
    {
        return filled(config('services.api_co_id.key'));
    }

    /** @param array<string,string|int|float> $query
     * @return array<mixed> */
    public static function get(string $path, array $query = []): array
    {
        $quota = 'api-co-id:quota:'.($path === 'v2/rates' ? 'rates' : 'masters');
        if (! self::enabled()) {
            throw new CourierFailure(null, 'Kunci penyedia ongkir belum dikonfigurasi.');
        }
        if (Cache::has($quota)) {
            throw new CourierFailure(429, 'Kuota penyedia ongkir habis.');
        }
        try {
            $r = Http::acceptJson()->withUserAgent('Snowgo2 Shipping')->withHeaders(['x-api-co-id' => config('services.api_co_id.key')])
                ->withoutRedirecting()->connectTimeout(2)->timeout(6)->get('https://use.api.co.id/courier/'.$path, $query);
        } catch (ConnectionException) {
            throw new CourierFailure(null, 'Penyedia ongkir tidak dapat dijangkau.');
        }
        if ($r->status() === 429) {
            $reset = $r->json('reset_at');
            try {
                $until = is_string($reset) && $reset !== '' ? Carbon::parse($reset) : null;
            } catch (\Exception) {
                $until = null;
            }
            Cache::put($quota, true, $until?->isFuture() ? $until : now()->addMinute());
        }
        if (! $r->ok() || $r->json('is_success') !== true || ! is_array($data = $r->json('data'))) {
            $message = match ($r->status()) {
                400 => 'Parameter wilayah atau berat tidak valid.',
                401, 403 => 'Autentikasi ke penyedia ongkir gagal.',
                429 => 'Kuota penyedia ongkir habis.',
                default => 'Penyedia ongkir tidak tersedia.',
            };
            throw new CourierFailure($r->status(), $message);
        }

        return $data;
    }
}
