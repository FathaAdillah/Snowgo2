<?php

namespace App\Modules\Shipping;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class PollTracking
{
    public function run(TrackingProvider $provider, int $carrier): void
    {
        $backoff = "shipping:poll:backoff:$carrier";
        $shipments = DB::table('shipments')->where('carrier_id', $carrier)->whereIn('status', array_keys(Tracking::TRANSITIONS));
        foreach ($shipments->lazyById(100) as $shipment) {
            if (Cache::has($backoff)) {
                return;
            }
            $response = $provider->poll($shipment->tracking_number);
            if (isset($response['retry_after'])) {
                Cache::put($backoff, true, max(1, $response['retry_after']));

                return;
            }
            foreach ($response['events'] as $event) {
                $status = match (strtolower($event['status'])) {
                    'transit' => 'IN_TRANSIT',
                    'out_for_delivery' => 'OUT_FOR_DELIVERY',
                    'delivered' => 'DELIVERED',
                    'failed' => 'FAILED',
                    'returned' => 'RETURNED',
                    default => 'UNKNOWN',
                };
                app(Tracking::class)->append($shipment->number, 'provider:'.hash('sha256', $event['id']), $status, Carbon::parse($event['at']), null, providerStatus: $event['status']);
            }
        }
    }
}
