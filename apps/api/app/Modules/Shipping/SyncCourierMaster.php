<?php

namespace App\Modules\Shipping;

use Illuminate\Console\Command;

final class SyncCourierMaster extends Command
{
    protected $signature = 'shipping:sync-master {group : couriers, provinces, cities or districts} {parent? : Provider province or city code} {--bind=* : Verified provider-code:local-region-code under this parent}';

    protected $description = 'Sync a courier reference list without changing local IDs or active flags';

    public function handle(): int
    {
        $group = $this->argument('group');
        $parent = $this->argument('parent');
        $bindings = [];
        foreach ($this->option('bind') as $pair) {
            if (! preg_match('/^(\d{2,6}):([A-Za-z0-9_.-]{1,48})$/D', $pair, $m) || isset($bindings[$m[1]])) {
                $this->error('Gunakan --bind=kode-provider:kode-lokal sekali per wilayah.');

                return self::INVALID;
            }
            $bindings[$m[1]] = $m[2];
        }
        $rows = CourierMasterSync::fetch($group, $parent);
        $this->info('Master diproses: '.CourierMasterSync::apply($group, $parent, $rows, null, $bindings));

        return self::SUCCESS;
    }
}
