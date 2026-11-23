<?php

namespace App\Modules\Geography;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegionBatch
{
    private const KINDS = ['COUNTRY', 'PROVINCE', 'CITY', 'DISTRICT'];

    /** @var array<string,array{kind:string,code:string,name:string,parent:?string,active:?bool}> */
    private array $rows = [];

    /** @param resource $stream */
    public static function csv($stream): self
    {
        $batch = new self;
        if (self::record($stream) !== ['kind', 'code', 'name', 'parent_code', 'active']) {
            throw ValidationException::withMessages(['file_id' => 'Expected header: kind,code,name,parent_code,active']);
        }
        while (($row = self::record($stream)) !== null) {
            if (! in_array($row[4], ['0', '1'], true)) {
                throw ValidationException::withMessages(['file_id' => 'Invalid CSV row; active must be 0 or 1.']);
            }
            [$kind, $code, $name, $parent] = $row;
            $batch->add($kind, $code, $name, $parent === '' ? null : $parent, $row[4] === '1');
        }

        return $batch;
    }

    /**
     * Region fields are single-line. Never let an unmatched quote consume the next record.
     *
     * @param  resource  $stream
     * @return array<int,string|null>|null
     */
    private static function record($stream): ?array
    {
        $line = fgets($stream, 2049);
        if ($line === false && feof($stream)) {
            return null;
        }
        $field = '(?:[^",\r\n]*+|"(?:[^"\r\n]++|"")*+")';
        if ($line === false || strlen($line) >= 2048 || ! preg_match('/\A'.$field.'(?:,'.$field.'){4}(?:\r?\n)?\z/', $line)) {
            throw ValidationException::withMessages(['file_id' => 'Expected five CSV fields with balanced quotes on one line.']);
        }

        return str_getcsv(rtrim($line, "\r\n"), escape: '');
    }

    public function add(string $kind, string $code, string $name, ?string $parent, ?bool $active): void
    {
        $name = trim($name);
        $valid = '/\A[A-Za-z0-9_.-]{1,48}\z/D';
        if (! preg_match($valid, $code) || ($parent !== null && ! preg_match($valid, $parent))
            || ! in_array($kind, self::KINDS, true) || $name === '' || mb_strlen($name) > 120
            || ! mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0")
            || ($kind === 'COUNTRY') !== ($parent === null) || ($kind === 'COUNTRY' && $code !== 'ID')
            || isset($this->rows[$code]) || count($this->rows) >= 20000) {
            throw ValidationException::withMessages(['regions' => 'Invalid or duplicate region, or batch exceeds 20,000 rows.']);
        }
        $this->rows[$code] = compact('kind', 'code', 'name', 'parent', 'active');
    }

    /** A null active flag (upstream sync) preserves local archival decisions. */
    public function import(?int $actorId): int
    {
        if (! $this->rows) {
            throw ValidationException::withMessages(['regions' => 'Empty batch.']);
        }
        try {
            return DB::transaction(function () use ($actorId) {
                References::lockRegions();
                DB::statement('SET CONSTRAINTS regions_active_parent_fk DEFERRED');
                $changed = 0;
                foreach (self::KINDS as $kind) {
                    $rows = array_values(array_filter($this->rows, fn ($r) => $r['kind'] === $kind));
                    if (! $rows) {
                        continue;
                    }
                    $json = json_encode($rows, JSON_THROW_ON_ERROR);
                    $from = 'FROM jsonb_to_recordset(?::jsonb) AS i(kind text, code text, name text, parent text, active boolean)
                        LEFT JOIN regions p ON p.code = i.parent LEFT JOIN regions r ON r.code = i.code';
                    if (DB::scalar("SELECT EXISTS (SELECT 1 $from WHERE
                        (i.parent IS NOT NULL AND p.id IS NULL) OR
                        (r.id IS NOT NULL AND (r.kind <> i.kind OR r.parent_id IS DISTINCT FROM p.id)))", [$json])) {
                        throw ValidationException::withMessages(['regions' => 'Missing parent or code already bound to a different hierarchy.']);
                    }
                    $changed += DB::affectingStatement("INSERT INTO regions (kind, code, name, parent_id, active)
                        SELECT i.kind, i.code, i.name, p.id, COALESCE(i.active,r.active,p.active,true) $from
                        ON CONFLICT (code) DO UPDATE SET name = excluded.name, active = excluded.active, updated_at = now()
                        WHERE (regions.name, regions.active) IS DISTINCT FROM (excluded.name, excluded.active)", [$json]);
                }
                DB::statement('SET CONSTRAINTS regions_active_parent_fk IMMEDIATE');
                if ($changed) {
                    $id = (int) DB::table('regions')->where('code', 'ID')->value('id');
                    app(Journal::class)->audit('RegionImported', Subject::Region, $id, $actorId, after: ['quantity' => $changed]);
                    app(Outbox::class)->record('RegionImported', 'region', $id, ['changed' => $changed]);
                }

                return $changed;
            });
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23503', '23514'], true)) {
                throw ValidationException::withMessages(['regions' => 'Invalid parent level or active descendant of an inactive region.']);
            }
            throw $e;
        }
    }
}
