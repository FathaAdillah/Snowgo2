<?php

namespace App\Modules\Orders;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CheckoutOrders
{
    /** @param array<string,mixed> $group
     * @param array<string,mixed> $address
     * @return array{id:int,lines:list<array<string,mixed>>} */
    public function create(?int $checkout, int $owner, array $group, array $address, string $due, ?string $notes, ?int $requestOrder = null): array
    {
        $number = sprintf('ORD-%s-%06d', now()->format('Ymd'), DB::scalar("SELECT nextval('order_number_seq')"));
        $id = DB::table('orders')->insertGetId(Arr::only($group, ['campaign_id', 'term', 'subtotal_amount', 'discount_amount', 'tax_amount', 'shipping_amount', 'total_amount']) +
            ['number' => $number, 'checkout_id' => $checkout, 'request_order_id' => $requestOrder, 'user_id' => $owner, 'selling_method' => $group['method'],
                'payment_due_at' => $due, 'notes' => $notes, 'campaign_terms' => $group['campaign_terms'] === null ? null : json_encode($group['campaign_terms'], JSON_THROW_ON_ERROR)]);
        $address['region_snapshot'] = json_encode($address['region_snapshot'], JSON_THROW_ON_ERROR);
        DB::table('order_addresses')->insert($address + ['order_id' => $id]);
        $lines = [];
        foreach ($group['lines'] as $line) {
            $line['id'] = DB::table('order_items')->insertGetId(Arr::except($line, ['campaign_variant_id']) +
                ['public_id' => (string) Str::ulid(), 'order_id' => $id]);
            $lines[] = $line;
        }
        $journal = app(Journal::class);
        $journal->transition(Subject::Order, $id, 'status', null, 'PENDING_PAYMENT', "order-created:$id", $owner);
        $journal->audit('OrderCreated', Subject::Order, $id, $owner, null, ['status' => 'PENDING_PAYMENT', 'amount_idr' => $group['total_amount']]);
        app(Outbox::class)->record('OrderCreated', 'order', $id, ['number' => $number]);

        return compact('id', 'lines');
    }
}
