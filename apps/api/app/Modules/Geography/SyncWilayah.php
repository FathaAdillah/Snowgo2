<?php

namespace App\Modules\Geography;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class SyncWilayah extends Command
{
    protected $signature = 'geography:sync-wilayah {--parent= : Fetch children of ID, province, or regency from GitHub Pages} {--snapshot= : Import a complete local v2 directory instead}';

    protected $description = 'Import Indonesian provinces, regencies and districts without changing existing active flags';

    public function handle(WilayahSource $source): int
    {
        $snapshot = $this->option('snapshot');
        $parent = $this->option('parent');
        if ($snapshot !== null && $parent !== null) {
            $this->error('Choose either --parent or --snapshot.');

            return self::INVALID;
        }
        try {
            $batch = $snapshot === null ? $source->page($parent ?? 'ID') : $source->snapshot($snapshot);
            $this->info('Changed regions: '.$batch->import(null));
        } catch (ValidationException $e) {
            $this->error(implode(' ', array_merge(...array_values($e->errors()))));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
