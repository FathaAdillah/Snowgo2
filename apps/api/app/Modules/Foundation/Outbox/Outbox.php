<?php

namespace App\Modules\Foundation\Outbox;

use App\Support\Http\RequestId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class Outbox
{
    /** @param array<string,mixed> $payload */
    public function record(string $type, string $aggregate, int $id, array $payload): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Record the event in the domain transaction.');
        }
        $event = DB::table('outbox_events')->insertGetId([
            'event_uuid' => (string) Str::uuid(), 'event_type' => $type, 'aggregate_type' => $aggregate,
            'aggregate_id' => $id, 'payload' => json_encode((object) $payload, JSON_THROW_ON_ERROR),
            'request_id' => Context::get('request_id') ?? RequestId::current(),
        ]);

        foreach (array_keys(config('outbox.handlers')[$type] ?? []) as $handler) {
            DB::table('event_consumptions')->insert(['event_id' => $event, 'handler' => $handler, 'status' => 'PROCESSING']);
        }

        return $event;
    }
}
