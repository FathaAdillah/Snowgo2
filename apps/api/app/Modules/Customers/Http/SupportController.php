<?php

namespace App\Modules\Customers\Http;

use App\Models\User;
use App\Modules\Customers\Addresses\AddressBook;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Orders\OrderDirectory;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupportController
{
    private const ROW = 'array{id:int,email:string,display_name:string,active:bool,created_at:string,address_count:int,order_count:int}';

    private function customers(): Builder
    {
        return DB::table('users as u')->where('u.account_type', User::CUSTOMER)
            ->join('customer_profiles as p', 'p.user_id', '=', 'u.id');
    }

    private function summary(): Builder
    {
        return $this->customers()->select('u.id', 'u.email', 'p.display_name', 'u.created_at')
            ->selectRaw('u.disabled_at IS NULL AS active')
            ->selectSub(OrderDirectory::scope(null)->selectRaw('count(*)')->whereColumn('o.user_id', 'u.id'), 'order_count')
            ->selectSub(DB::table('addresses as a')->selectRaw('count(*)')->whereColumn('a.user_id', 'u.id')->whereNull('a.deleted_at'), 'address_count');
    }

    #[Response(200, 'Customer directory', type: 'array{success:bool,message:string,data:list<'.self::ROW.'>,meta:'.CustomerResponse::META.'}')]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([...ApiInput::PAGE, 'q' => 'sometimes|required|string|max:120']);
        ApiInput::fields($request, $filters);
        $query = $this->summary()->orderByDesc('u.id');
        if (isset($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '\\%_').'%';
            $query->where(fn ($q) => $q->whereLike('u.email', $term)->orWhereLike('p.display_name', $term));
        }

        return CustomerResponse::page($query, $filters);
    }

    #[Response(404, 'Customer not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Customer detail', type: 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}')]
    public function show(Request $request, int $id): JsonResponse
    {
        ApiInput::fields($request, []);

        return CustomerResponse::ok($this->summary()->where('u.id', $id)->first() ?? abort(404));
    }

    private function accessed(string $event, int $id, int $staff): void
    {
        abort_unless($this->customers()->where('u.id', $id)->exists(), 404);
        app(Journal::class)->audit($event, Subject::User, $id, $staff);
        app(Outbox::class)->record($event, 'customer', $id, ['actor_id' => $staff]);
    }

    #[Response(404, 'Customer not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Audited customer addresses', type: CustomerResponse::ADDRESSES)]
    public function addresses(Request $request, int $id): JsonResponse
    {
        $filters = $request->validate(ApiInput::PAGE);
        ApiInput::fields($request, $filters);

        return DB::transaction(function () use ($request, $id, $filters) {
            $this->accessed('CustomerAddressAccessed', $id, $request->user()->id);

            return CustomerResponse::page(AddressBook::listing($id), $filters);
        });
    }

    #[Response(404, 'Customer not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Audited activity', type: "array{success:bool,message:string,data:list<array{kind:'ORDER'|'ORDER_STATUS'|'SUPPORT_ACCESS',reference:string,status:string|null,occurred_at:string}>,meta:array{request_id:string,next_cursor:string|null}}")]
    public function activity(Request $request, int $id): JsonResponse
    {
        $filters = $request->validate(['cursor' => 'sometimes|required|string|max:128', 'per_page' => ApiInput::PAGE['per_page'],
            'from' => 'sometimes|required|date', 'to' => 'sometimes|required|date']);
        ApiInput::fields($request, $filters);
        $cursor = isset($filters['cursor']) ? base64_decode($filters['cursor'], true) : null;
        if ($cursor !== null && (! is_string($cursor) || ! ctype_digit($cursor) || (string) (int) $cursor !== $cursor || (int) $cursor <= 0)) {
            throw ValidationException::withMessages(['cursor' => 'Invalid activity cursor.']);
        }
        $from = $request->date('from');
        $to = $request->date('to');
        if ($from !== null && $to !== null && $from->gt($to)) {
            throw ValidationException::withMessages(['to' => 'Must not precede from.']);
        }

        return DB::transaction(function () use ($request, $id, $filters, $cursor, $from, $to) {
            $this->accessed('CustomerActivityAccessed', $id, $request->user()->id);
            $limit = (int) ($filters['per_page'] ?? 20);
            $support = DB::table('audit_logs')->where('subject_type', Subject::User->value)->where('subject_id', $id)
                ->whereIn('action', ['CustomerAddressAccessed', 'CustomerActivityAccessed'])
                ->selectRaw("id, occurred_at, 'SUPPORT_ACCESS' AS kind, 'SUP-' || id AS reference, NULL::text AS status");
            if ($request->user()->can('orders.order.view')) {
                $support->unionAll(OrderDirectory::scope($id)->join('audit_logs as a', 'a.subject_id', '=', 'o.id')
                    ->where('a.subject_type', Subject::Order->value)->whereIn('a.action', ['OrderCreated', 'OrderCancelled', 'OrderCompleted', 'OrderExpired', 'OrderStatusChanged'])
                    ->selectRaw("a.id, a.occurred_at, CASE WHEN a.action='OrderCreated' THEN 'ORDER' ELSE 'ORDER_STATUS' END, o.number, a.after->>'status'"));
            }
            $rows = DB::query()->fromSub($support, 'activity')
                ->when($cursor !== null, fn ($q) => $q->where('id', '<', $cursor))
                ->when($from !== null, fn ($q) => $q->where('occurred_at', '>=', $from?->toIso8601String()))
                ->when($to !== null, fn ($q) => $q->where('occurred_at', '<=', $to?->toIso8601String()))
                ->orderByDesc('id')->limit($limit + 1)->get();
            $page = $rows->take($limit);

            return CustomerResponse::ok($page->map(fn ($row) => ['kind' => $row->kind, 'reference' => $row->reference,
                'status' => $row->status, 'occurred_at' => Carbon::parse($row->occurred_at)->toISOString()])->all(),
                meta: ['next_cursor' => $rows->count() > $limit ? base64_encode((string) $page->last()->id) : null]);
        });
    }
}
