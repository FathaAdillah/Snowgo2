<?php

namespace App\Modules\Procurement;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BatchLifecycle
{
    private const NEXT = [
        'PENDING_PURCHASE' => ['PURCHASED', 'FAILED'], 'PURCHASED' => ['SELLER_SHIPPED', 'FAILED'],
        'SELLER_SHIPPED' => ['AT_ORIGIN_WAREHOUSE', 'INTERNATIONAL_TRANSIT', 'FAILED'],
        'AT_ORIGIN_WAREHOUSE' => ['INTERNATIONAL_TRANSIT', 'FAILED'],
        'INTERNATIONAL_TRANSIT' => ['AT_DESTINATION_WAREHOUSE', 'FAILED'],
        'AT_DESTINATION_WAREHOUSE' => ['IMPORT_FEE_ASSESSMENT', 'RECEIVED', 'FAILED'],
        'IMPORT_FEE_ASSESSMENT' => ['RECEIVED', 'FAILED'],
    ];

    /** @param array<string,mixed> $data */
    public function create(array $data, int $actor): string
    {
        $source = BatchSources::resolve($data);
        $lines = BatchSources::lines($source, array_column($data['items'], 'order_item_id'));
        $number = sprintf('BAT-%s-%06d', now()->format('Ymd'), DB::scalar("SELECT nextval('procurement_batch_number_seq')"));
        $id = DB::table('procurement_batches')->insertGetId($source + ['number' => $number] + Arr::only($data, ['eta_date', 'supplier_ref', 'notes']));
        foreach ($data['items'] as $row) {
            $item = $lines[$row['order_item_id']];
            if ($row['qty'] > BatchSources::remaining($item)) {
                throw new ApiProblem(409, 'QUANTITY_CONFLICT', 'Quantity already assigned, failed or received.');
            }
            DB::table('procurement_batch_items')->insert(['batch_id' => $id, 'order_item_id' => $item->id, 'qty' => $row['qty']]);
        }
        $this->recordStage($id, 'PENDING_PURCHASE', $actor, null, $data['eta_date'] ?? null);
        app(Journal::class)->transition(Subject::Batch, $id, 'status', null, 'OPEN', 'batch:OPEN', $actor);
        app(Journal::class)->audit('ProcurementBatchCreated', Subject::Batch, $id, $actor, null, ['status' => 'OPEN']);
        app(Outbox::class)->record('ProcurementBatchCreated', 'procurement_batch', $id, ['number' => $number]);

        return $number;
    }

    /** @param array<string,mixed> $data */
    public function stage(object $b, array $data, int $actor): void
    {
        $next = $data['stage'];
        $items = DB::table('procurement_batch_items')->where('batch_id', $b->id)->get();
        if (! in_array($next, self::nextStages($b, $items->contains(fn ($i) => $i->received_qty + $i->failed_qty < $i->qty), $items->sum('received_qty') > 0), true)) {
            throw self::invalid();
        }
        $status = match ($next) {
            'RECEIVED' => 'RECEIVED',
            'FAILED' => $b->status,
            default => 'IN_PROGRESS',
        };
        $eta = $data['eta_date'] ?? $b->eta_date;
        DB::table('procurement_batches')->where('id', $b->id)->update(['status' => $status, 'stage' => $next, 'eta_date' => $eta]);
        $this->recordStage($b->id, $next, $actor, $data['note'] ?? null, $eta);
        if ($status !== $b->status) {
            app(Journal::class)->transition(Subject::Batch, $b->id, 'status', $b->status, $status, 'batch:'.$status, $actor);
        }
        app(Journal::class)->audit('ProcurementStageChanged', Subject::Batch, $b->id, $actor, (array) $b, ['status' => $status, 'stage' => $next, 'eta_date' => $eta]);
        app(Outbox::class)->record('ProcurementStageChanged', 'procurement_batch', $b->id, ['number' => $b->number, 'stage' => $next, 'eta_date' => $eta]);
    }

    private function recordStage(int $id, string $stage, int $actor, ?string $note, ?string $eta): void
    {
        DB::table('procurement_stage_events')->insert(['batch_id' => $id, 'stage' => $stage, 'actor_id' => $actor, 'note' => $note, 'eta_date' => $eta, 'event_key' => 'stage:'.$stage]);
    }

    public static function outstanding(int $id): bool
    {
        return DB::table('procurement_batch_items')->where('batch_id', $id)->whereRaw('qty>received_qty+failed_qty')->exists();
    }

    /** @return list<string> */
    public static function nextStages(object $b, bool $outstanding, bool $anyReceived): array
    {
        return ! in_array($b->status, ['OPEN', 'IN_PROGRESS'], true) ? [] : array_values(array_filter(self::NEXT[$b->stage] ?? [],
            fn ($s) => match ($s) {
                'RECEIVED' => ! $outstanding && $anyReceived,
                'FAILED' => $outstanding || ! $anyReceived,
                default => true,
            }));
    }

    public static function closure(object $b, bool $outstanding): ?string
    {
        return match (true) {
            $b->status === 'RECEIVED' => 'CLOSED',
            $b->stage === 'FAILED' && $b->status === 'IN_PROGRESS' && ! $outstanding => 'CLOSED',
            $b->status === 'OPEN' || ($b->stage === 'FAILED' && $b->status === 'IN_PROGRESS') => 'CANCELLED',
            default => null,
        };
    }

    public function close(object $b, ?string $reason, int $actor): void
    {
        $status = self::closure($b, self::outstanding($b->id)) ?? throw self::invalid();
        if ($reason === null && ($b->stage === 'FAILED' || $status === 'CANCELLED')) {
            throw ValidationException::withMessages(['reason_code' => 'A procurement reason is required.']);
        }
        $reasonId = self::reason($reason);
        DB::table('procurement_batches')->where('id', $b->id)->update(['status' => $status, 'closure_reason' => $reason]);
        app(Journal::class)->transition(Subject::Batch, $b->id, 'status', $b->status, $status, 'batch:'.$status, $actor, $reasonId);
        app(Journal::class)->audit('ProcurementBatchClosed', Subject::Batch, $b->id, $actor, ['status' => $b->status], ['status' => $status]);
        app(Outbox::class)->record('ProcurementBatchClosed', 'procurement_batch', $b->id, ['number' => $b->number, 'status' => $status]);
    }

    public static function reason(?string $code): ?int
    {
        return $code === null ? null : DB::table('reason_codes')->where(['domain' => 'procurement', 'code' => $code, 'active' => true])->sharedLock()->value('id')
            ?? throw ValidationException::withMessages(['reason_code' => 'Select an active procurement reason.']);
    }

    public static function invalid(): ApiProblem
    {
        return new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Batch cannot make this transition.');
    }
}
