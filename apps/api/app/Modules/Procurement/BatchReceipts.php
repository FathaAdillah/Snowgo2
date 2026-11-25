<?php

namespace App\Modules\Procurement;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Orders\OrderSupply;
use App\Modules\Preorders\AllocationLifecycle;
use App\Modules\Preorders\CampaignWindow;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BatchReceipts
{
    /** Quantities are deltas; the HTTP idempotency transaction owns the source and batch locks.
     * @param array<string,mixed> $data */
    public function apply(object $b, array $data, int $actor): void
    {
        if ($b->status !== 'IN_PROGRESS' || $b->stage === 'FAILED') {
            throw BatchLifecycle::invalid();
        }
        $reason = $data['reason_code'] ?? null;
        $reasonId = BatchLifecycle::reason($reason);
        $source = $b->campaign_id === null ? ['request_order_id' => $b->request_order_id] : ['campaign_id' => $b->campaign_id];
        $items = BatchSources::lines($source, array_column($data['items'], 'order_item_id'));
        $lines = DB::table('procurement_batch_items')->where('batch_id', $b->id)->whereIn('order_item_id', $items->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('order_item_id');
        abort_unless($lines->count() === $items->count(), 404);
        $positive = false;
        $receiptIds = [];
        foreach ($data['items'] as $row) {
            $item = $items[$row['order_item_id']];
            $line = $lines[$item->id];
            $received = (int) $row['received_qty'];
            $failed = (int) $row['failed_qty'];
            if (! ($received + $failed)) {
                throw ValidationException::withMessages(['items' => 'Record a positive receipt or shortage.']);
            }
            if ($failed && $reasonId === null) {
                throw ValidationException::withMessages(['reason_code' => 'Final supplier shortage requires an active reason.']);
            }
            if ($received + $failed > min($line->qty - $line->received_qty - $line->failed_qty, $item->qty - $item->received_qty - $item->cancelled_qty)) {
                throw new ApiProblem(409, 'QUANTITY_CONFLICT', 'Receipt exceeds unresolved units.');
            }
            $receipt = DB::table('procurement_receipt_lines')->insertGetId(['batch_item_id' => $line->id, 'actor_id' => $actor,
                'received_qty' => $received, 'failed_qty' => $failed, 'note' => $data['note'] ?? null, 'reason_code' => $reason]);
            $receiptIds[] = $receipt;
            DB::table('procurement_batch_items')->where('id', $line->id)->update(['received_qty' => $line->received_qty + $received, 'failed_qty' => $line->failed_qty + $failed]);
            $ref = 'procurement-receipt:'.$receipt;
            OrderSupply::receive($item, $received, $failed, $ref);
            if ($failed) {
                if ($b->campaign_id !== null) {
                    AllocationLifecycle::recordShortage($item->id, $failed, $actor, $reasonId);
                }
                app(Outbox::class)->record('SupplierShortageReviewRequested', 'order', $item->order_id, [
                    'source_ref' => $ref, 'order_item_id' => $item->public_id, 'failed_qty' => $failed, 'batch_number' => $b->number,
                ]);
            }
            $positive = $positive || $received > 0;
            app(Journal::class)->audit('ProcurementReceiptRecorded', Subject::Batch, $b->id, $actor, null, ['order_item_id' => $item->public_id, 'received_qty' => $received, 'failed_qty' => $failed]);
        }
        if ($positive && $b->campaign_id !== null) {
            app(CampaignWindow::class)->recordReceipt($b->campaign_id);
        }
        app(Outbox::class)->record('ProcurementReceiptRecorded', 'procurement_batch', $b->id, ['number' => $b->number, 'receipt_ids' => $receiptIds]);
        if (! BatchLifecycle::outstanding($b->id)) {
            $anyReceived = DB::table('procurement_batch_items')->where('batch_id', $b->id)->where('received_qty', '>', 0)->exists();
            if (! $anyReceived || in_array($b->stage, ['AT_DESTINATION_WAREHOUSE', 'IMPORT_FEE_ASSESSMENT'], true)) {
                app(BatchLifecycle::class)->stage($b, ['stage' => $anyReceived ? 'RECEIVED' : 'FAILED', 'note' => $data['note'] ?? null], $actor);
            }
        }
    }
}
