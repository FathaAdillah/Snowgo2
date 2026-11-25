<?php

namespace App\Modules\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FinanceReport
{
    public const METHODS = ['ready_stock' => 'READY_TO_SHIP', 'preorder' => 'PREORDER', 'request_order' => 'REQUEST_ORDER'];

    public static function ledger(string $from, string $until, ?string $method): Builder
    {
        $base = DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->join('invoices as i', 'i.id', '=', 'a.invoice_id')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereNotNull('a.applied_at')->when($method, fn ($q) => $q->where('o.selling_method', $method))
            ->select('a.id as allocation_id', 'o.selling_method', 'o.number as order_number', 'i.number as invoice_number', 'p.number as payment_number');
        $paid = (clone $base)->where('a.applied_at', '>=', $from)->where('a.applied_at', '<', $until)
            ->selectRaw("'CAPTURE' as kind, null::varchar as refund_number, a.applied_at as posted_at, a.amount, 0::bigint as transfer_id");
        $refund = $base->join('refund_transactions as rt', 'rt.payment_allocation_id', '=', 'a.id')
            ->join('refund_transfers as t', 't.id', '=', 'rt.transfer_id')->join('refunds as r', 'r.id', '=', 't.refund_id')
            ->where('r.status', 'COMPLETED')->where('t.transferred_at', '>=', $from)->where('t.transferred_at', '<', $until)
            ->selectRaw("'REFUND' as kind, r.number as refund_number, t.transferred_at as posted_at, -rt.amount as amount, t.id as transfer_id");

        return DB::query()->fromSub($paid->unionAll($refund), 'ledger');
    }

    /** @return array<string,mixed> */
    public static function dashboard(string $from, string $until, ?string $method): array
    {
        $orders = DB::table('orders as o')->when($method, fn ($q) => $q->where('o.selling_method', $method));
        $new = (clone $orders)->where('o.created_at', '>=', $from)->where('o.created_at', '<', $until);
        $dailyOrders = $new->selectRaw("selling_method, (created_at AT TIME ZONE 'Asia/Jakarta')::date as day, count(*) as orders")->groupBy('selling_method', 'day')->get();
        $dailyLedger = self::ledger($from, $until, $method)->selectRaw("selling_method, (posted_at AT TIME ZONE 'Asia/Jakarta')::date as day,
            sum(greatest(amount,0)) as paid, -sum(least(amount,0)) as refunded")->groupBy('selling_method', 'day')->get();
        $byMethod = [];
        foreach (self::METHODS as $slug => $dbMethod) {
            if (! $method || $dbMethod === $method) {
                $byMethod[] = ['method' => $slug, 'orders_count' => (int) $dailyOrders->where('selling_method', $dbMethod)->sum('orders'),
                    'paid_amount' => (int) $dailyLedger->where('selling_method', $dbMethod)->sum('paid')];
            }
        }
        $trend = [];
        $start = CarbonImmutable::parse($from)->setTimezone('Asia/Jakarta');
        $end = CarbonImmutable::parse($until);
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $trend[] = ['date' => $date, 'orders' => (int) $dailyOrders->where('day', $date)->sum('orders'), 'amount' => (int) $dailyLedger->where('day', $date)->sum('paid')];
        }
        $paid = (int) $dailyLedger->sum('paid');
        $refunded = (int) $dailyLedger->sum('refunded');
        $scope = $orders->select('o.id');
        $pending = DB::table('payment_allocations as a')->join('invoices as i', 'i.id', '=', 'a.invoice_id')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')->whereIn('i.order_id', $scope)->whereIn('p.status', ['WAITING_CONFIRMATION', 'UNDER_REVIEW'])->distinct()->count('p.id');

        return ['orders_count' => (int) $dailyOrders->sum('orders'), 'by_method' => $byMethod, 'paid_amount' => $paid, 'refund_amount' => $refunded, 'net_amount' => $paid - $refunded,
            'pending_payments' => $pending, 'shipment_failures' => DB::table('shipments')->whereIn('order_id', $scope)->where('status', 'FAILED')->count(),
            'open_returns' => DB::table('returns')->whereIn('order_id', $scope)->whereNotIn('status', ['RESOLVED', 'CANCELLED', 'REJECTED'])->count(),
            'open_refunds' => DB::table('refunds')->whereIn('order_id', $scope)->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'REJECTED'])->count(),
            'low_stock_count' => DB::table('inventory_levels as l')->join('warehouses as w','w.id','=','l.warehouse_id')->where('w.active',true)->whereColumn('l.available','<=','l.reorder_point')->count(),
            'trend' => $trend];
    }
}
