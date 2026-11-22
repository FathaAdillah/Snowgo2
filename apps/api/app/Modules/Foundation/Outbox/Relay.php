<?php

namespace App\Modules\Foundation\Outbox;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use LogicException;

final class Relay
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function publish(): int
    {
        $this->requireAutocommit();
        $claims = DB::selectFromWriteConnection(<<<'SQL'
            UPDATE outbox_events e SET attempts = attempts + 1, lease_until = now() + interval '1 minute'
            WHERE id IN (
                SELECT id FROM outbox_events WHERE published_at IS NULL AND (lease_until IS NULL OR lease_until <= now())
                ORDER BY occurred_at, id LIMIT 100 FOR UPDATE SKIP LOCKED
            ) RETURNING id, attempts
            SQL);

        foreach ($claims as $event) {
            $receipts = DB::table('event_consumptions')->where('event_id', $event->id)->where('status', 'PROCESSING');
            $receipts->update(['lease_until' => now()->addMinutes(15), 'updated_at' => now()]);
            foreach ($receipts->pluck('id') as $id) {
                $this->bus->dispatch(new ConsumeEvent($id));
            }
            DB::table('outbox_events')->where('id', $event->id)->where('attempts', $event->attempts)
                ->update(['published_at' => now(), 'lease_until' => null]);
        }

        return count($claims);
    }

    public function reconcile(): int
    {
        $this->requireAutocommit();
        $missing = DB::selectFromWriteConnection(<<<'SQL'
            UPDATE event_consumptions SET lease_until = now() + interval '15 minutes', updated_at = now()
            WHERE id IN (
                SELECT c.id FROM event_consumptions c JOIN outbox_events e ON e.id = c.event_id
                WHERE e.published_at IS NOT NULL AND c.status = 'PROCESSING' AND c.lease_until <= now()
                ORDER BY c.lease_until, c.id LIMIT 100 FOR UPDATE OF c SKIP LOCKED
            ) RETURNING id
            SQL);
        foreach ($missing as $receipt) {
            $this->bus->dispatch(new ConsumeEvent($receipt->id));
        }

        return count($missing);
    }

    private function requireAutocommit(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Commit relay claims before publishing to the queue.');
        }
    }
}
