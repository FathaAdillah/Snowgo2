<?php

namespace App\Modules\Foundation\Outbox;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class ConsumeEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $consumptionId)
    {
        $this->onQueue('critical');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $error = DB::transaction(function () {
            $row = DB::table('event_consumptions')->where('id', $this->consumptionId);
            $receipt = (clone $row)->lockForUpdate()->firstOrFail();
            if ($receipt->status === 'COMPLETED') {
                return null;
            }

            $record = DB::table('outbox_events')->where('id', $receipt->event_id)->firstOrFail();
            $row->increment('attempts', extra: ['updated_at' => now()]);
            try {
                DB::transaction(function () use ($record, $receipt) {
                    $class = config('outbox.handlers')[$record->event_type][$receipt->handler] ?? null;
                    if (! is_string($class) || ! is_subclass_of($class, EventHandler::class)) {
                        throw new LogicException("Missing outbox handler: {$receipt->handler}");
                    }
                    $event = new RecordedEvent(
                        $record->id, $record->event_uuid, $record->event_type, $record->aggregate_type,
                        $record->aggregate_id, json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR),
                    );
                    Context::scope(fn () => app($class)->handle($event), ['request_id' => $record->request_id]);
                });
            } catch (Throwable $e) {
                // Persist the attempt, not partial handler effects. Horizon owns retry/backoff.
                return $e;
            }
            $row->update(['status' => 'COMPLETED', 'completed_at' => now(), 'lease_until' => null, 'updated_at' => now()]);

            return null;
        });
        if ($error !== null) {
            throw $error;
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('event_consumptions')->where('id', $this->consumptionId)->where('status', '!=', 'COMPLETED')
            ->update(['status' => 'FAILED', 'lease_until' => null, 'updated_at' => now()]);
    }
}
