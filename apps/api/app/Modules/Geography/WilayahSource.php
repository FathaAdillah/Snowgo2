<?php

namespace App\Modules\Geography;

use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

final class WilayahSource
{
    public const BASE = 'https://fathaadillah.github.io/api-wilayah-indonesia/v2/';

    public function page(string $parent): RegionBatch
    {
        $batch = new RegionBatch;
        [$path, $level] = $this->path($parent);
        $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)->get(self::BASE.$path);
        if (! $response->ok()) {
            throw new UnexpectedValueException("Wilayah returned HTTP {$response->status()}.");
        }
        $this->append($batch, $response->json(), $parent, $level);

        return $batch;
    }

    /** Load one published gh-pages/v2 snapshot; never mix pages from different releases. */
    public function snapshot(string $directory): RegionBatch
    {
        $read = static function (string $path) use ($directory): mixed {
            $file = rtrim($directory, '/').'/'.$path;
            if (! is_file($file) || ! is_readable($file)) {
                throw new UnexpectedValueException("Missing snapshot page: $path");
            }

            return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        };
        $stats = $read('stats.json');
        $date = $stats['meta']['updated_at'] ?? null;
        if (! is_string($date) || $date === '') {
            throw new UnexpectedValueException('Missing snapshot date.');
        }
        $batch = new RegionBatch;
        $parents = ['ID'];
        foreach (['total_provinces', 'total_regencies', 'total_districts'] as $total) {
            $next = [];
            foreach ($parents as $parent) {
                [$path, $level] = $this->path($parent);
                $page = $read($path);
                if (($page['meta']['updated_at'] ?? null) !== $date) {
                    throw new UnexpectedValueException("Inconsistent snapshot date: $path");
                }
                array_push($next, ...$this->append($batch, $page, $parent, $level));
            }
            if (($stats['data'][$total] ?? null) !== count($next) || ! $next) {
                throw new UnexpectedValueException("Incomplete snapshot: $total");
            }
            $parents = $next;
        }

        return $batch;
    }

    /** @return array{string,int} */
    private function path(string $parent): array
    {
        return match (true) {
            $parent === 'ID' => ['provinces.json', 1],
            (bool) preg_match('/^\d{2}$/D', $parent) => ["regencies/$parent.json", 2],
            (bool) preg_match('/^\d{2}\.\d{2}$/D', $parent) => ["districts/$parent.json", 3],
            default => throw new UnexpectedValueException('Parent must be ID, a province code (11), or a regency code (11.01).'),
        };
    }

    /** @return list<string> */
    private function append(RegionBatch $batch, mixed $page, string $parent, int $level): array
    {
        if (($page['meta']['level'] ?? null) !== $level || ! is_array($page['data'] ?? null)
            || ! array_is_list($page['data']) || $page['data'] === []) {
            throw new UnexpectedValueException('Invalid v2 wilayah list.');
        }
        if ($parent === 'ID') {
            $batch->add('COUNTRY', 'ID', 'Indonesia', null, null);
        }
        $ids = [];
        $pattern = $level === 1 ? '/^\d{2}$/D' : '/^'.preg_quote($parent, '/').'\.\d{2}$/D';
        foreach ($page['data'] as $row) {
            $code = $row['id'] ?? null;
            if (! is_string($code) || ! preg_match($pattern, $code) || ! is_string($row['name'] ?? null)) {
                throw new UnexpectedValueException("Invalid child of $parent.");
            }
            $batch->add([1 => 'PROVINCE', 2 => 'CITY', 3 => 'DISTRICT'][$level], $code, $row['name'], $parent, null);
            $ids[] = $code;
        }

        return $ids;
    }
}
