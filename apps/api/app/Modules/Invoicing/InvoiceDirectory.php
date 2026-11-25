<?php

namespace App\Modules\Invoicing;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class InvoiceDirectory
{
    private const INVOICE = "array{number:string,order_number:string,order_status:string,replaces_invoice_number:string|null,replaced_by_invoice_number:string|null,purpose:'FULL_PAYMENT'|'DEPOSIT'|'BALANCE'|'ADDITIONAL_CHARGE',status:'ISSUED'|'PAID'|'VOID',currency:string,subtotal_amount:int,discount_amount:int,tax_amount:int,shipping_amount:int,total_amount:int,issued_at:string,due_at:string,paid_at:string|null,items:list<array{order_item_id:string|null,covered_qty:int|null,type:string,description:string,qty:int,unit_amount:int,amount:int}>,pdf_ready:bool,pdf_status:'PENDING'|'RENDERING'|'FAILED'|'READY'}";

    public const PAGE = 'array{success:bool,message:string,data:list<'.self::INVOICE.'>,meta:array{request_id:string,page:int,last_page:int,per_page:int,total:int}}';

    public const ONE = 'array{success:bool,message:string,data:'.self::INVOICE.',meta:array{request_id:string}}';

    public const DOWNLOAD = 'array{success:bool,message:string,data:array{url:string,expires_at:string},meta:array{request_id:string}}';

    public static function rows(?int $owner): Builder
    {
        return DB::table('invoices as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('invoice_documents as d', 'd.invoice_id', '=', 'i.id')->leftJoin('invoices as old', 'old.id', '=', 'i.replaces_invoice_id')
            ->leftJoin('invoices as replacement', 'replacement.replaces_invoice_id', '=', 'i.id')
            ->where('i.status', '<>', 'DRAFT')->when($owner !== null, fn ($q) => $q->where('o.user_id', $owner))
            ->select('i.number', 'o.number as order_number', 'i.purpose', 'i.status', 'i.currency', 'i.subtotal_amount', 'i.discount_amount', 'i.tax_amount',
                'i.shipping_amount', 'i.total_amount', 'i.issued_at', 'i.due_at', 'i.paid_at', 'old.number as replaces_invoice_number',
                'o.status as order_status', 'replacement.number as replaced_by_invoice_number')
            ->selectRaw("coalesce(d.status,'PENDING') as pdf_status,(SELECT json_agg(json_build_object('type',x.line_type,'description',x.description,
                'order_item_id',(SELECT public_id FROM order_items WHERE id=x.order_item_id),'covered_qty',x.covered_qty,'qty',x.qty,'unit_amount',x.unit_amount,'amount',x.amount) ORDER BY x.id) FROM invoice_items x WHERE x.invoice_id=i.id) AS items");
    }

    /** @return array<string,mixed> */
    public static function data(object $row): array
    {
        $data = (array) $row;
        foreach (['issued_at', 'due_at', 'paid_at'] as $key) {
            $data[$key] = $data[$key] === null ? null : Carbon::parse($data[$key])->toISOString();
        }
        $data['pdf_ready'] = $row->pdf_status === 'READY';
        $data['items'] = json_decode($row->items ?? '[]', true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
