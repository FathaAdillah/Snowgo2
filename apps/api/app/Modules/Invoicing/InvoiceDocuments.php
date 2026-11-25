<?php

namespace App\Modules\Invoicing;

use App\Modules\Foundation\Outbox\EventHandler;
use App\Modules\Foundation\Outbox\RecordedEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;

final class InvoiceDocuments implements EventHandler
{
    public function handle(RecordedEvent $event): void
    {
        DB::table('invoice_documents')->insertOrIgnore(['invoice_id' => $event->aggregateId]);
    }

    public static function dispatchDue(Dispatcher $bus): int
    {
        $jobs = DB::selectFromWriteConnection(<<<'SQL'
            WITH due AS MATERIALIZED (
                SELECT invoice_id FROM invoice_documents WHERE status='PENDING' OR (status='RENDERING' AND lease_until <= now())
                ORDER BY invoice_id LIMIT 25 FOR UPDATE SKIP LOCKED
            )
            UPDATE invoice_documents d SET status='RENDERING', token=gen_random_uuid(), lease_until=now()+interval '10 minutes'
            FROM due WHERE d.invoice_id=due.invoice_id RETURNING d.invoice_id,d.token
            SQL);
        foreach ($jobs as $job) {
            $bus->dispatch(new RenderInvoicePdf($job->invoice_id, $job->token));
        }

        return count($jobs);
    }
}
