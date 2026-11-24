<?php

namespace App\Modules\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockExpiry
{
    /** Claim only work rows in a short transaction. No child lock survives into the order transaction.
     * @return list<object{order_id:int,lease_token:string}>
     */
    public function claim(int $limit = 100): array
    {
        return DB::select(<<<'SQL'
            WITH candidates AS (
                SELECT order_id FROM stock_expiry_work WHERE due_at<=clock_timestamp()
                AND (lease_until IS NULL OR lease_until<=clock_timestamp())
                ORDER BY due_at,order_id LIMIT ? FOR UPDATE SKIP LOCKED
            )
            UPDATE stock_expiry_work w SET lease_token=?::uuid,lease_until=clock_timestamp()+interval '60 seconds'
            FROM candidates c WHERE w.order_id=c.order_id RETURNING w.order_id,w.lease_token
            SQL, [min(max(1, $limit), 500), (string) Str::uuid()]);
    }

    public function run(StockReservations $stock): int
    {
        $count = 0;
        foreach ($this->claim() as $work) {
            $count += (int) $stock->expire($work->order_id, $work->lease_token);
        }

        return $count;
    }
}
