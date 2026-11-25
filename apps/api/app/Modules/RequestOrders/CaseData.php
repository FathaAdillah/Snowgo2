<?php

namespace App\Modules\RequestOrders;

use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CaseData
{
    private const FIELDS = 'number:string,title:string,status:string,target_date:string|null,created_at:string';

    private const ITEM = 'array{id:string,name:string,source_url:string|null,options:string|null,notes:string|null,qty:int}';

    private const MSG = 'array{body:string,visibility:string,author_label:string,created_at:string}';

    private const DETAIL = self::FIELDS.',description:string,budget_amount:int|null,items:list<'.self::ITEM.'>,messages:list<'.self::MSG.'>,files:list<array{id:string,mime:string,size_bytes:int,status:string}>,quotations:list<array{number:string,version:int,status:string}>';

    private const STAFF = 'array{'.self::DETAIL.',assigned_to:int|null,review_actions:list<string>,can_message:bool}';

    private const META = 'meta:array{request_id:string,page:int,per_page:int,total:int,last_page:int}';

    public const PAGE = 'array{success:bool,message:string,data:list<array{'.self::FIELDS.'}>,'.self::META.'}';

    public const STAFF_PAGE = 'array{success:bool,message:string,data:list<'.self::STAFF.'>,'.self::META.'}';

    public const SUMMARY = 'array{success:bool,message:string,data:array{'.self::FIELDS.'},meta:array{request_id:string}}';

    public const ONE = 'array{success:bool,message:string,data:array{'.self::DETAIL.'},meta:array{request_id:string}}';

    public const STAFF_ONE = 'array{success:bool,message:string,data:'.self::STAFF.',meta:array{request_id:string}}';

    public const MESSAGE = 'array{success:bool,message:string,data:'.self::MSG.',meta:array{request_id:string}}';

    public const FILTERS = [...ApiInput::PAGE, 'status' => 'sometimes|required|string|in:SUBMITTED,UNDER_REVIEW,NEED_INFORMATION,QUOTED,REVISION_REQUESTED,CONVERTED_TO_ORDER,EXPIRED,REJECTED,CANCELLED'];

    public static function scope(?int $owner): Builder
    {
        return DB::table('request_orders as r')->when($owner !== null, fn ($q) => $q->where('r.user_id', $owner));
    }

    public static function locked(string $number, ?int $owner): object
    {
        return self::scope($owner)->where('number', $number)->lockForUpdate()->first() ?? abort(404);
    }

    private static function projection(?int $owner, bool $detail): Builder
    {
        $q = self::scope($owner)->select('r.number', 'r.title', 'r.status', 'r.target_date', 'r.created_at');
        if (! $detail) {
            return $q;
        }
        if ($owner === null) {
            $q->addSelect('r.assigned_to');
        }

        return $q->addSelect('r.description', 'r.budget_amount')->selectRaw(<<<'SQL'
            (SELECT json_agg(json_build_object('id',i.public_id,'name',i.name,'qty',i.qty,'options',i.options,'source_url',i.source_url,'notes',i.notes) ORDER BY i.id)
                FROM request_order_items i WHERE i.request_order_id=r.id) AS items,
            (SELECT json_agg(json_build_object('body',m.body,'visibility',m.visibility,'author_label',CASE WHEN m.author_id=r.user_id THEN 'Pelanggan' ELSE 'Staf' END,'created_at',m.created_at) ORDER BY m.id)
                FROM request_order_messages m WHERE m.request_order_id=r.id AND (m.visibility='CUSTOMER' OR ?::boolean)) AS messages,
            (SELECT json_agg(json_build_object('id',f.public_id,'mime',f.mime,'size_bytes',f.size_bytes,'status',f.status) ORDER BY f.id)
                FROM stored_files f WHERE f.owner_type='request_order' AND f.owner_id=r.id AND f.collection='request_attachment' AND f.status<>'DELETED') AS files
            SQL, [$owner === null]);
    }

    private static function format(object $row): object
    {
        $row->created_at = Carbon::parse($row->created_at)->toISOString();
        if (property_exists($row, 'messages')) {
            foreach (['items', 'messages', 'files'] as $field) {
                $row->$field = json_decode($row->$field ?? '[]', flags: JSON_THROW_ON_ERROR);
            }
            $row->quotations = [];
        }
        if (property_exists($row, 'assigned_to')) {
            $row->review_actions = array_keys(RequestCases::REVIEWS[$row->status] ?? []);
            $row->can_message = in_array($row->status, RequestCases::DISCUSS, true);
        }

        return $row;
    }

    /** @param array<string,mixed> $data */
    public static function page(Request $request, array $data, ?int $owner): JsonResponse
    {
        ApiInput::fields($request, $data);
        $q = self::projection($owner, $owner === null)->when($data['status'] ?? null, fn ($q, $s) => $q->where('r.status', $s));
        if (isset($data['from'], $data['to']) && $data['to'] < $data['from']) {
            throw ValidationException::withMessages(['to' => 'Must not precede from.']);
        }
        $q->when($data['number'] ?? null, fn ($q, $number) => $q->where('r.number', $number));
        if ($from = $request->date('from', tz: 'Asia/Jakarta')) {
            $q->where('r.created_at', '>=', $from->startOfDay()->toIso8601String());
        }
        if ($to = $request->date('to', tz: 'Asia/Jakarta')) {
            $q->where('r.created_at', '<', $to->startOfDay()->addDay()->toIso8601String());
        }
        $p = $q->orderByDesc('r.id')->paginate((int) ($data['per_page'] ?? 20), page: (int) ($data['page'] ?? 1));

        return ApiResponse::success(array_map(self::format(...), $p->items()), meta: ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()])->header('Cache-Control', 'private, no-store');
    }

    public static function one(string $number, ?int $owner, bool $detail = true, int $status = 200): JsonResponse
    {
        $row = self::projection($owner, $detail)->where('r.number', $number)->first() ?? abort(404);

        return ApiResponse::success(self::format($row), status: $status)->header('Cache-Control', 'private, no-store');
    }

    public static function message(int $id, bool $staff): JsonResponse
    {
        $message = DB::table('request_order_messages')->where('id', $id)->first(['visibility', 'body', 'created_at']);
        $message->author_label = $staff ? 'Staf' : 'Pelanggan';
        $message->created_at = Carbon::parse($message->created_at)->toISOString();

        return ApiResponse::success($message);
    }
}
