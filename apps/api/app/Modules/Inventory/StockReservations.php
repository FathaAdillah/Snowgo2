<?php

namespace App\Modules\Inventory;

use App\Modules\Catalog\ProductDirectory;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Orders\StockOwner;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Internal RTS-only port. The calling domain verifies its checkout/capture/cancellation/shipment
 * and calls inside that same transaction, after locking all affected orders in ascending ID order.
 */
final class StockReservations
{
    /** @return list<int> reservation IDs in item order */
    public function reserve(int $orderId): array
    {
        return DB::transaction(function () use ($orderId) {
            $order = StockOwner::lock($orderId);
            $items = StockOwner::lines()->where('i.order_id', $orderId)->orderBy('i.id')->get();
            $intent = hash('sha256', json_encode([$order->user_id, $order->payment_due_at, $items], JSON_THROW_ON_ERROR));
            $existing = $this->holds($orderId);
            if ($existing->isNotEmpty()) {
                if ($existing->count() !== $items->count() || $existing->contains(fn ($h) => $h->reserve_hash !== $intent)) {
                    throw $this->invalid();
                }

                return $existing->pluck('id')->all();
            }
            if ($items->isEmpty() || $items->contains(fn ($i) => ! $i->sku_snapshot || ! $i->variant_id)) {
                throw $this->invalid();
            }
            $warehouse = DB::table('warehouses')->where(['active' => true, 'is_default' => true])->sharedLock()->value('id');
            $eligible = ProductDirectory::variants()->whereIn('id', $items->pluck('variant_id'))->where('rts_enabled', true);
            $products = ProductDirectory::published()->whereIn('id', (clone $eligible)->select('product_id'))->orderBy('id')->sharedLock()->pluck('id');
            $variants = $eligible->whereIn('product_id', $products)
                ->orderBy('id')->sharedLock()->pluck('sku', 'id');
            $levels = DB::table('inventory_levels')->where('warehouse_id', $warehouse)->whereIn('variant_id', $variants->keys())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('variant_id');
            if ($this->due($order->payment_due_at)) {
                throw $this->invalid();
            }
            $ids = [];
            foreach ($items as $item) {
                $level = $levels->get($item->variant_id);
                if (! $level || $variants->get($item->variant_id) !== $item->sku_snapshot || $level->available < $item->qty) {
                    throw new ApiProblem(409, 'OUT_OF_STOCK', 'Not enough eligible stock in the default warehouse.');
                }
                $level->available -= $item->qty;
                $id = DB::table('stock_reservations')->insertGetId(['order_item_id' => $item->id, 'level_id' => $level->id,
                    'qty' => $item->qty, 'status' => 'ACTIVE', 'expires_at' => $order->payment_due_at, 'reserve_hash' => $intent]);
                $this->post($id, $level->id, 'RESERVATION', "reserve:$id", 0, $item->qty, 0, null, 'ACTIVE');
                $ids[] = $id;
            }
            DB::table('stock_expiry_work')->insert(['order_id' => $orderId, 'due_at' => $order->payment_due_at]);

            return $ids;
        });
    }

    /** Verified initial capture only. Late money must go to Payments reconciliation, never revive an expired hold. */
    public function commit(int $orderId, int $captureId): void
    {
        $this->activeCommand($orderId, 'COMMIT', $captureId);
    }

    public function release(int $orderId): void
    {
        $this->activeCommand($orderId, 'CANCEL');
    }

    public function expire(int $orderId, string $lease): bool
    {
        return $this->activeCommand($orderId, 'EXPIRE', lease: $lease);
    }

    public function extendForProof(int $orderId, int $proofId): void
    {
        DB::transaction(function () use ($orderId, $proofId) {
            StockOwner::lock($orderId);
            $work = DB::table('stock_expiry_work')->where('order_id', $orderId);
            $current = $work->lockForUpdate()->first();
            if (! $current || $this->due($current->due_at) || $proofId <= 0 || ($current->proof_id !== null && $current->proof_id !== $proofId)) {
                throw $this->invalid();
            }
            if ($current->proof_id !== null) {
                return;
            }
            $deadline = DB::scalar("SELECT clock_timestamp()+interval '24 hours'");
            $work->update(['proof_id' => $proofId, 'due_at' => $deadline, 'lease_token' => null, 'lease_until' => null]);
            foreach ($this->holds($orderId) as $hold) {
                if ($hold->status !== 'ACTIVE') {
                    throw $this->invalid();
                }
                DB::table('stock_reservations')->where('id', $hold->id)->update(['expires_at' => $deadline]);
                app(Journal::class)->audit('StockHoldExtended', Subject::StockReservation, $hold->id, null,
                    ['expires_at' => $hold->expires_at], ['expires_at' => $deadline]);
                app(Outbox::class)->record('StockHoldExtended', 'stock_reservation', $hold->id, ['expires_at' => $deadline, 'proof_id' => $proofId]);
            }
        });
    }

    private function activeCommand(int $orderId, string $action, ?int $capture = null, ?string $lease = null): bool
    {
        return DB::transaction(function () use ($orderId, $action, $capture, $lease) {
            StockOwner::lock($orderId);
            $holds = $this->holds($orderId);
            $this->lockLevels($holds);
            $work = DB::table('stock_expiry_work')->where('order_id', $orderId)->lockForUpdate()->first();
            $expired = $work && $this->due($work->due_at);
            if ($action === 'EXPIRE' && (! $expired || $work->lease_token !== $lease || $this->due($work->lease_until))) {
                return false;
            }
            if ($holds->isEmpty() || ($action === 'COMMIT' && ($capture < 1 || $expired))) {
                throw $this->invalid();
            }
            foreach ($holds as $hold) {
                $key = match ($action) {
                    'COMMIT' => "commit:$capture:$hold->id",
                    'EXPIRE' => "expire:$hold->id",
                    default => "cancel:$hold->id",
                };
                if (DB::table('inventory_movements')->where('source_key', $key)->exists()) {
                    continue;
                }
                if (! $work || $hold->status !== 'ACTIVE') {
                    throw $this->invalid();
                }
                $commit = $action === 'COMMIT';
                $to = $commit ? 'COMMITTED' : ($action === 'EXPIRE' ? 'EXPIRED' : 'RELEASED');
                DB::table('stock_reservations')->where('id', $hold->id)->update(['status' => $to, 'released_qty' => $commit ? 0 : $hold->qty]);
                $this->post($hold->id, $hold->level_id, $commit ? 'COMMIT' : 'RESERVATION_RELEASE', $key, 0, -$hold->qty, $commit ? $hold->qty : 0, 'ACTIVE', $to);
            }
            DB::table('stock_expiry_work')->where('order_id', $orderId)->delete();

            return true;
        });
    }

    public function ship(int $orderId, int $reservationId, int $qty, int $shipmentItemId): void
    {
        $this->consume($orderId, $reservationId, $qty, $shipmentItemId, true);
    }

    public function releaseCommitted(int $orderId, int $reservationId, int $qty, int $cancellationLineId): void
    {
        $this->consume($orderId, $reservationId, $qty, $cancellationLineId, false);
    }

    private function consume(int $orderId, int $reservationId, int $qty, int $sourceId, bool $sale): void
    {
        DB::transaction(function () use ($orderId, $reservationId, $qty, $sourceId, $sale) {
            StockOwner::lock($orderId);
            $holds = $this->holds($orderId);
            $hold = $holds->firstWhere('id', $reservationId) ?? throw $this->invalid();
            $this->lockLevels($holds);
            $key = ($sale ? 'ship:' : 'commit-release:').$sourceId;
            if ($sourceId <= 0 || $qty < 1) {
                throw $this->quantity();
            }
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["inventory:$key"]);
            $prior = DB::table('inventory_movements')->where('source_key', $key)->first();
            if ($prior) {
                if ($prior->reservation_id !== $hold->id || $prior->committed_delta !== -$qty) {
                    throw $this->invalid();
                }

                return;
            }
            if ($hold->status !== 'COMMITTED') {
                throw $this->invalid();
            }
            $remaining = $hold->qty - $hold->shipped_qty - $hold->released_qty;
            if ($qty > $remaining) {
                throw $this->quantity();
            }
            $shipped = $hold->shipped_qty + ($sale ? $qty : 0);
            $to = $remaining > $qty ? 'COMMITTED' : ($shipped > 0 ? 'CONSUMED' : 'RELEASED');
            DB::table('stock_reservations')->where('id', $hold->id)->update(['shipped_qty' => $shipped,
                'released_qty' => $hold->released_qty + ($sale ? 0 : $qty), 'status' => $to]);
            $this->post($hold->id, $hold->level_id, $sale ? 'SALE' : 'COMMIT_RELEASE', $key, $sale ? -$qty : 0, 0, -$qty, 'COMMITTED', $to);
        });
    }

    /** @return Collection<int,stdClass> */
    private function holds(int $orderId): Collection
    {
        return DB::table('stock_reservations')->whereIn('order_item_id', StockOwner::lines()->where('i.order_id', $orderId)->select('i.id'))
            ->orderBy('order_item_id')->get();
    }

    /** @param Collection<int,stdClass> $holds */
    private function lockLevels(Collection $holds): void
    {
        DB::table('inventory_levels')->whereIn('id', $holds->pluck('level_id'))->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function due(string $time): bool
    {
        return (bool) DB::scalar('SELECT ?::timestamptz <= clock_timestamp()', [$time]);
    }

    private function post(int $id, int $level, string $type, string $key, int $physical, int $reserved, int $committed, ?string $from, string $to): void
    {
        $movement = DB::table('inventory_movements')->insertGetId(['reservation_id' => $id, 'level_id' => $level, 'type' => $type,
            'source_key' => $key, 'on_hand_delta' => $physical, 'reserved_delta' => $reserved, 'committed_delta' => $committed]);
        $event = match ($type) {
            'RESERVATION' => 'StockReserved', 'COMMIT' => 'StockCommitted', 'SALE' => 'StockShipped', default => 'StockReleased'
        };
        $journal = app(Journal::class);
        if ($from !== $to) {
            $journal->transition(Subject::StockReservation, $id, 'status', $from, $to, $key);
        }
        $journal->audit($event, Subject::StockReservation, $id, null, null, ['status' => $to, 'quantity' => max(abs($physical), abs($reserved), abs($committed))]);
        app(Outbox::class)->record($event, 'stock_reservation', $id, ['level_id' => $level, 'movement_id' => $movement]);
    }

    private function invalid(): ApiProblem
    {
        return new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Reservation owner, source or state does not allow this operation.');
    }

    private function quantity(): ApiProblem
    {
        return new ApiProblem(409, 'QUANTITY_CONFLICT', 'Insufficient eligible RTS units or invalid quantity.');
    }
}
