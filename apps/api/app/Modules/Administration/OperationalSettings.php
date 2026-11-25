<?php

namespace App\Modules\Administration;

use Illuminate\Support\Facades\DB;

final class OperationalSettings
{
    public static function value(string $key): int
    {
        return (int) DB::table('settings')->where('key', $key)->soleValue('value');
    }
}
