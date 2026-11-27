<?php

namespace App\Modules\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchNotifications
{
    public function run(): int
    {
        DB::table('notification_deliveries')->where('status', 'SENDING')->where('lease_until', '<=', now())
            ->update(['status' => 'AMBIGUOUS', 'lease_until' => null, 'updated_at' => now()]);

        $due = DB::table('notification_deliveries')->where('status', 'PENDING')->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->orderBy('id')->limit(100)->pluck('id');

        return $due->filter(static fn (int $id) => self::enqueue($id))->count();
    }

    public static function enqueue(int $id): bool
    {
        try {
            SendNotificationEmail::dispatch($id);

            return true;
        } catch (Throwable) {
            Log::warning('Notification email queue dispatch failed', ['delivery_id' => $id]);

            return false;
        }
    }
}
