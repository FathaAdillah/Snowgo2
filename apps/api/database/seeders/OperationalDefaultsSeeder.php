<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class OperationalDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['inventory.reservation_ttl_minutes', 60, 5, 180], ['payment.verification_hold_hours', 24, 1, 48],
            ['returns.window_hours', (int) config('aftersales.return_window_hours'), 1, 168],
            ['orders.auto_complete_hours', (int) config('aftersales.completion_delay_hours'), 48, 720],
        ];
        foreach ($settings as [$key,$value,$minimum,$maximum]) {
            DB::table('settings')->insertOrIgnore(compact('key', 'value', 'minimum', 'maximum'));
        }
    }
}
