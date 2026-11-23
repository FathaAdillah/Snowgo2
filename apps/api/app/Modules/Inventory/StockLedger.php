<?php

namespace App\Modules\Inventory;

use App\Modules\Catalog\ProductDirectory;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockLedger
{
    /** @param array<string,mixed> $input */
    public function receive(array $input, int $actor, string $key): int
    {
        return DB::transaction(function () use ($input, $actor, $key) {
            $warehouse = DB::table('warehouses')->where('id', $input['warehouse_id'])->where('active', true)->sharedLock()->first() ?? abort(404);
            $variant = ProductDirectory::variants()->where('sku', $input['sku'])->sharedLock()->first(['id']) ?? abort(404);
            $pair = ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id];
            DB::table('inventory_levels')->insertOrIgnore($pair);
            $level = DB::table('inventory_levels')->where($pair)->lockForUpdate()->firstOrFail();
            if ($input['type'] === 'INITIAL' && DB::table('inventory_movements')->where('level_id', $level->id)->exists()) {
                throw new ApiProblem(409, 'QUANTITY_CONFLICT', 'Opening stock must be the first movement.');
            }
            $this->post($level, $input['type'], (int) $input['qty'], $input, $actor, 'receipt', $key);

            return $level->id;
        });
    }

    /** @param array<string,mixed> $input */
    public function adjust(array $input, int $actor, string $key): int
    {
        return DB::transaction(function () use ($input, $actor, $key) {
            $levels = DB::table('inventory_levels')->where('id', $input['level_id']);
            $identity = $levels->first(['warehouse_id', 'variant_id']) ?? abort(404);
            DB::table('warehouses')->where(['id' => $identity->warehouse_id, 'active' => true])->sharedLock()->first(['id']) ?? abort(404);
            ProductDirectory::variants()->where('id', $identity->variant_id)->sharedLock()->first(['id']) ?? abort(404);
            $level = $levels->lockForUpdate()->firstOrFail();
            $delta = (int) $input['delta'];
            if ($delta === 0) {
                throw ValidationException::withMessages(['delta' => 'Adjustment quantity must be non-zero.']);
            }
            [$movement, $reason] = $this->post($level, $delta > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT', $delta, $input, $actor, 'adjustment', $key);

            return DB::table('stock_adjustments')->insertGetId(['movement_id' => $movement, 'level_id' => $level->id, 'actor_id' => $actor,
                'reason_id' => $reason, 'delta' => $delta, 'note' => $input['note'] ?? null]);
        });
    }

    /** @param array<string,mixed> $input
     * @return array{int,int} Movement and reason IDs. */
    private function post(object $level, string $type, int $delta, array $input, int $actor, string $operation, string $key): array
    {
        if ($level->available + $delta < 0 || $level->on_hand + $delta > 2147483647) {
            throw new ApiProblem(409, 'QUANTITY_CONFLICT', 'Quantity exceeds the available stock or level limit.');
        }
        $reason = DB::table('reason_codes')->where(['domain' => 'inventory', 'code' => $input['reason_code'], 'active' => true])->sharedLock()->value('id')
            ?? throw ValidationException::withMessages(['reason_code' => 'Select an active inventory reason.']);
        $movement = DB::table('inventory_movements')->insertGetId(['level_id' => $level->id, 'actor_id' => $actor, 'type' => $type,
            'source_key' => $operation.':'.hash('sha256', "$actor:$key"), 'reason_id' => $reason, 'note' => $input['note'] ?? null, 'on_hand_delta' => $delta]);
        $event = $operation === 'receipt' ? 'StockReceived' : 'StockAdjusted';
        app(Journal::class)->audit($event, Subject::Inventory, $level->id, $actor, ['quantity' => $level->on_hand], ['quantity' => $level->on_hand + $delta]);
        app(Outbox::class)->record($event, 'inventory', $level->id, ['movement_id' => $movement, 'delta' => $delta]);

        return [$movement, $reason];
    }
}
