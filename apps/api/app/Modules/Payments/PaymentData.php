<?php

namespace App\Modules\Payments;

use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Identity\Staff\StaffAccess;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class PaymentData
{
    public const FIELDS = 'number:string,status:string,amount:int,currency:string,invoice_numbers:list<string>,expires_at:string,submitted_at:string|null,verified_at:string|null,claim_until:string|null,claimed_by_me:bool,reason_code:string|null,proof_ids:list<string>,bank_code?:string,holder_name?:string,account_number?:string';

    public const ONE = 'array{success:bool,message:string,data:array{'.self::FIELDS.'},meta:array{request_id:string}}';

    public const PAGE = 'array{success:bool,message:string,data:list<array{'.self::FIELDS.'}>,meta:array{request_id:string,page:int,last_page:int,per_page:int,total:int}}';

    public static function rows(?int $owner): Builder
    {
        return DB::table('payments as p')->leftJoin('reason_codes as r', 'r.id', '=', 'p.rejection_reason_id')->select('p.*', 'r.code as reason_code')
            ->selectRaw('(SELECT json_agg(i.number ORDER BY i.number) FROM payment_allocations a JOIN invoices i ON i.id=a.invoice_id WHERE a.payment_id=p.id) AS invoice_numbers')
            ->selectRaw('(SELECT json_agg(f.public_id ORDER BY pf.id) FROM payment_proofs pf JOIN stored_files f ON f.id=pf.file_id WHERE pf.payment_id=p.id) AS proof_ids')
            ->when($owner !== null, fn ($q) => $q->where('p.user_id', $owner));
    }

    /** @return array<string,mixed> */
    public static function present(object $p, int $actor, bool $instruction, bool $proof): array
    {
        $data = ['number' => $p->number, 'status' => $p->status, 'amount' => $p->amount, 'currency' => $p->currency, 'reason_code' => $p->reason_code,
            'claimed_by_me' => $p->claim_by === $actor, 'invoice_numbers' => json_decode($p->invoice_numbers, true, flags: JSON_THROW_ON_ERROR),
            'proof_ids' => $proof ? json_decode($p->proof_ids ?? '[]', true, flags: JSON_THROW_ON_ERROR) : []];
        foreach (['expires_at', 'submitted_at', 'verified_at', 'claim_until'] as $field) {
            $data[$field] = $p->$field ? Carbon::parse($p->$field)->toISOString() : null;
        }

        return $instruction && $p->user_id === $actor ? $data + json_decode(Crypt::decryptString($p->instruction_snapshot_ciphertext), true, flags: JSON_THROW_ON_ERROR) : $data;
    }

    public static function detail(string $number, Request $request): JsonResponse
    {
        $actor = $request->user();
        $staff = $request->routeIs('payments.staff.*');
        $p = self::rows($staff ? null : $actor->id)->where('p.number', $number)->first() ?? abort(404);

        return ApiResponse::success(self::present($p, $actor->id, ! $staff, ! $staff || $actor->can('payments.proof.view')))->header('Cache-Control', 'private, no-store');
    }

    /** @param array<string,mixed> $data
     * @param  list<string>  $abilities
     * @param  Closure():JsonResponse  $action
     */
    public static function mutate(Request $request, array $data, array $abilities, Closure $action): JsonResponse
    {
        ApiInput::fields($request, $data);
        $authorize = function () use ($request, $abilities) {
            $actor = $request->user();
            if ($abilities) {
                abort_unless(! $request->session()->has(StaffAccess::PASSWORD_ONLY_VERSION) && $actor->fresh()?->can($abilities), 403);
            }

            return 'user:'.$actor->id;
        };

        return app(IdempotentMutation::class)->run($request, $authorize, function () use ($action, $authorize) {
            $authorize();

            return $action();
        })->header('Cache-Control', 'private, no-store');
    }

    /** @param array<string,mixed> $filters */
    public static function page(Builder $query, array $filters, ?Closure $map = null): JsonResponse
    {
        $page = $query->paginate((int) ($filters['per_page'] ?? 20), page: (int) ($filters['page'] ?? 1));

        return ApiResponse::success($map ? array_map($map, $page->items()) : $page->items(), meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()])->header('Cache-Control','private, no-store');
    }
}
