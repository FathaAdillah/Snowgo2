<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Identity\Accounts\CustomerAccounts;
use App\Modules\Identity\Contracts\CustomerDirectory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synthetic local/staging data. Mail fixtures appear in Mailpit once Horizon relays the outbox.
 */
class DatabaseSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'snowego-demo-password';

    public function run(CustomerAccounts $accounts, CustomerDirectory $directory): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must not be seeded in production.');
        }

        DB::transaction(function () use ($directory) {
            $customer = User::query()->firstOrNew(['email' => 'customer@snowego.test']);
            if (! $customer->exists) {
                $customer->forceFill(['password_hash' => self::DEMO_PASSWORD, 'account_type' => User::CUSTOMER, 'email_verified_at' => now()])->save();
            }
            $directory->open($customer->id, 'Pelanggan Demo');
        });

        // Unverified fixture: its verification email is queued through the outbox to Mailpit.
        $accounts->register('Pelanggan Belum Verifikasi', 'pending@snowego.test', self::DEMO_PASSWORD);
    }
}
