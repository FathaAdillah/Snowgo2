<?php

namespace App\Modules\Customers\Profiles;

use App\Modules\Identity\Contracts\CustomerDirectory;
use Illuminate\Support\Facades\DB;

final class CustomerProfiles implements CustomerDirectory
{
    public function open(int $userId, string $displayName): void
    {
        DB::table('customer_profiles')->insertOrIgnore(['user_id' => $userId, 'display_name' => $displayName]);
    }

    public function displayName(int $userId): ?string
    {
        $name = DB::table('customer_profiles')->where('user_id', $userId)->value('display_name');

        return is_string($name) ? $name : null;
    }
}
