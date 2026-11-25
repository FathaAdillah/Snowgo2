<?php

namespace App\Modules\RequestOrders;

use App\Support\Http\ApiProblem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class QuotationData
{
    public const ITEM = 'array{request_item_id:string,label:string,options:string|null,qty:int,unit_amount:int,weight_g:int|null,foreign_currency?:string|null,foreign_amount?:string|null,exchange_rate?:string|null}';

    public const SHAPE = 'array{number:string,version:int,revision:int,status:string,valid_until:string,term:string,subtotal_amount:int,total_amount:int,deposit_amount:int|null,shipping_estimate_amount:int|null,terms:string,items:list<'.self::ITEM.'>}';

    public const ONE = 'array{success:bool,message:string,data:'.self::SHAPE.',meta:array{request_id:string}}';

    public const DECISION = 'array{success:bool,message:string,data:array{quotation:'.self::SHAPE.',request_status:string,order_number?:string,invoice_number?:string},meta:array{request_id:string}}';

    public static function projection(bool $staff = true): Builder
    {
        return DB::table('quotations as q')->select('q.number', 'q.version', 'q.revision', 'q.status', 'q.valid_until', 'q.term', 'q.terms', 'q.subtotal_amount', 'q.total_amount', 'q.shipping_estimate_amount', 'q.deposit_amount')
            ->selectRaw(<<<'SQL'
                (SELECT json_agg(jsonb_build_object('request_item_id',r.public_id,'label',i.label,'options',i.options,'qty',i.qty,
                    'unit_amount',i.unit_amount,'weight_g',i.weight_g) || CASE WHEN ?::boolean THEN jsonb_build_object('foreign_currency',i.foreign_currency,
                    'foreign_amount',i.foreign_amount::text,'exchange_rate',i.exchange_rate::text) ELSE '{}'::jsonb END ORDER BY i.id)
                 FROM quotation_items i JOIN request_order_items r ON r.id=i.request_order_item_id WHERE i.quotation_id=q.id) AS items
                SQL, [$staff]);
    }

    public static function one(int $id, bool $staff = true): object
    {
        $q = self::projection($staff)->where('q.id', $id)->firstOrFail();
        $q->items = json_decode($q->items, flags: JSON_THROW_ON_ERROR);
        $q->valid_until = Carbon::parse($q->valid_until)->toISOString();

        return $q;
    }

    public static function locked(int $request, string $number, ?int $expectedRevision = null): object
    {
        $quote = DB::table('quotations')->where(['request_order_id' => $request, 'number' => $number])->lockForUpdate()->first() ?? abort(404);
        if ($expectedRevision !== null && $quote->status === 'DRAFT' && $expectedRevision !== $quote->revision) {
            throw new ApiProblem(409, 'VERSION_CONFLICT', 'The draft has changed. Reload it before confirming.');
        }

        return $quote;
    }
}
