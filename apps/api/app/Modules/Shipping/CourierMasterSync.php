<?php

namespace App\Modules\Shipping;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Geography\References;
use App\Modules\Geography\RegionBatch;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CourierMasterSync
{
    /** @return non-empty-list<array<string,mixed>> */
    public static function fetch(string $group, ?string $parent): array
    {
        $query = match (true) {
            $group === 'cities' && preg_match('/^\d{2}$/D', $parent ?? '') === 1 => ['province' => $parent],
            $group === 'districts' && preg_match('/^\d{4}$/D', $parent ?? '') === 1 => ['city' => $parent],
            in_array($group, ['provinces', 'couriers'], true) && $parent === null => [],
            default => throw ValidationException::withMessages(['parent' => 'Pilih jenis master dan kode induk yang sesuai.']),
        };
        $rows = CourierApi::get($group === 'couriers' ? 'v1/couriers' : 'v1/locations/'.$group, $query);
        if (! array_is_list($rows) || ! $rows || count($rows) > 2000) {
            throw self::invalid();
        }
        $seen = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                throw self::invalid();
            }
            $code = $r['code'] ?? null;
            $pattern = match ($group) {
                'provinces' => '/^\d{2}$/D', 'couriers' => '/^[a-z0-9_-]{1,32}$/Di',
                default => '/^'.$parent.'\d{2}$/D',
            };
            if (! is_string($code) || ! preg_match($pattern, $code) || isset($seen[strtoupper($code)])
                || ! is_string($r['name'] ?? null) || ! trim($r['name']) || mb_strlen($r['name']) > 120
                || ($group === 'couriers' && (! is_bool($r['cod'] ?? null) || (isset($r['logo']) && (! is_string($r['logo']) || strlen($r['logo']) > 512))))) {
                throw self::invalid();
            }
            $seen[strtoupper($code)] = true;
        }

        return $rows;
    }

    /** @param non-empty-list<array<string,mixed>> $rows
     * @param array<string,string> $bindings Verified provider code => existing local region code */
    public static function apply(string $group, ?string $parent, array $rows, ?int $actor, array $bindings = []): int
    {
        if ($bindings && ($group === 'couriers' || array_diff(array_keys($bindings), array_column($rows, 'code')))) {
            throw ValidationException::withMessages(['bind' => 'Kode provider tidak terdapat pada daftar wilayah ini.']);
        }

        return DB::transaction(function () use ($group, $parent, $rows, $actor, $bindings) {
            if ($group === 'couriers') {
                DB::select("SELECT pg_advisory_xact_lock(hashtextextended('couriers:sync',0))");
                foreach ($rows as $r) {
                    $mapping = DB::table('courier_provider_masters')->where('code', strtolower($r['code']))->first();
                    $code = strtoupper($r['code']);
                    if (! $mapping) {
                        DB::table('couriers')->insertOrIgnore(['code' => $code, 'name' => $r['name']]);
                    }
                    $id = $mapping->courier_id ?? DB::table('couriers')->where('code', $code)->value('id');
                    DB::table('courier_provider_masters')->updateOrInsert(['code' => strtolower($r['code'])], ['courier_id' => $id, 'logo' => $r['logo'] ?? null, 'cod' => $r['cod']]);
                    DB::table('couriers')->where('id', $id)->update(['name' => $r['name']]);
                }
                app(Journal::class)->audit('CourierMastersSynced', Subject::Courier, null, $actor, after: ['quantity' => count($rows)]);
            } else {
                References::lockRegions();
                $batch = new RegionBatch;
                if ($group === 'provinces') {
                    $batch->add('COUNTRY', 'ID', 'Indonesia', null, null);
                    $batch->import($actor);
                    $parentRow = DB::table('regions')->where('code', 'ID')->firstOrFail();
                } else {
                    $parentRow = DB::table('regions as r')->join('courier_region_codes as m', 'm.region_id', '=', 'r.id')
                        ->where('m.code', $parent)->where('r.kind', $group === 'cities' ? 'PROVINCE' : 'CITY')->first(['r.*'])
                        ?? throw ValidationException::withMessages(['parent' => 'Sinkronkan induk wilayah terlebih dahulu.']);
                }
                $kind = ['provinces' => 'PROVINCE', 'cities' => 'CITY', 'districts' => 'DISTRICT'][$group];
                $local = DB::table('regions')->where(['parent_id' => $parentRow->id, 'kind' => $kind])->get();
                $existing = DB::table('courier_region_codes')->whereIn('region_id', $local->pluck('id'))->pluck('code', 'region_id');
                $codes = [];
                $batch = new RegionBatch;
                foreach ($rows as $r) {
                    $mapped = DB::table('courier_region_codes')->where('code', $r['code'])->value('region_id');
                    $bind = $bindings[$r['code']] ?? null;
                    $matches = $local->filter(fn ($l) => $bind !== null ? $l->code === $bind : ($mapped !== null ? $l->id === $mapped : self::name($l->name) === self::name($r['name'])));
                    $match = $matches->first();
                    if ($matches->count() > 1 || ($bind !== null && ! $match) || ($mapped !== null && $match?->id !== $mapped)
                        || ($match && $existing->has($match->id) && $existing[$match->id] !== $r['code'])) {
                        throw ValidationException::withMessages(['bind' => 'Pemetaan wilayah ambigu atau bertentangan dengan induk/pemetaan sebelumnya: '.$r['code']]);
                    }
                    if ($bind === null && $mapped === null && $local->contains(fn ($l) => str_replace('.', '', $l->code) === $r['code'] && $l->id !== $match?->id)) {
                        throw ValidationException::withMessages(['bind' => 'Kode mirip tetapi nama wilayah berbeda. Periksa induk dan gunakan --bind untuk kode '.$r['code']]);
                    }
                    $code = $match->code ?? 'APICO-'.$r['code'];
                    $batch->add($kind, $code, $match->name ?? $r['name'], $parentRow->code, null);
                    $codes[$r['code']] = $code;
                }
                $batch->import($actor);
                $ids = DB::table('regions')->whereIn('code', $codes)->pluck('id', 'code');
                foreach ($codes as $remote => $code) {
                    DB::table('courier_region_codes')->upsert([['code' => (string) $remote, 'region_id' => $ids[$code]]], ['code'], ['region_id']);
                }
            }

            return count($rows);
        });
    }

    private static function name(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', str_replace(['kota administrasi ', 'kabupaten administrasi ', 'kab. '], ['kota ', 'kabupaten ', 'kabupaten '], strtolower($name)));
    }

    private static function invalid(): ApiProblem
    {
        return new ApiProblem(503, 'COURIER_MASTER_INVALID', 'Master kurir atau pemetaan wilayah tidak valid.');
    }
}
