<?php

namespace App\Modules\Payments\Http;

use App\Modules\Payments\BankAccounts;
use App\Modules\Payments\PaymentData;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BankController
{
    public const BANK = 'array{id:int,code:string,bank_code:string,holder_name:string,masked_number:string,active:bool}';

    public const ONE = 'array{success:bool,message:string,data:'.self::BANK.',meta:array{request_id:string}}';

    public const PAGE = 'array{success:bool,message:string,data:list<'.self::BANK.'>,meta:array{request_id:string,page:int,last_page:int,per_page:int,total:int}}';

    #[Response(200, 'Active transfer destinations', type: 'array{success:bool,message:string,data:list<'.self::BANK.'>,meta:array{request_id:string}}')]
    public function active(): JsonResponse
    {
        return ApiResponse::success(DB::table('bank_accounts')->select(BankAccounts::FIELDS)->where('active', true)->orderBy('code')->get());
    }

    #[Response(200, 'Bank accounts', type: self::PAGE)]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([...ApiInput::PAGE, 'q' => 'sometimes|required|string|max:100', 'active' => 'sometimes|string|in:0,1,true,false']);
        ApiInput::fields($request, $filters);
        $rows = DB::table('bank_accounts')->select(BankAccounts::FIELDS)->when(isset($filters['active']), fn ($q) => $q->where('active', $request->boolean('active')))
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('code', 'ilike', '%'.$s.'%')->orWhere('holder_name', 'ilike', '%'.$s.'%')))->orderBy('code');

        return PaymentData::page($rows, $filters);
    }

    #[Response(200, 'Bank account', type: self::ONE)]
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(DB::table('bank_accounts')->select(BankAccounts::FIELDS)->find($id) ?? abort(404));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Bank account', type: self::ONE)]
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|alpha_dash:ascii|max:32', 'bank_code' => 'required|string|alpha_dash:ascii|max:24', 'account_number' => 'required|string|regex:/\A[0-9]{6,32}\z/', 'holder_name' => 'required|string|max:160', 'active' => 'required|boolean']);

        return PaymentData::mutate($request, $data, ['payments.bank.view', 'payments.bank.manage', 'payments.bank.create'], fn () => ApiResponse::success(BankAccounts::save(null, $data, $request->user()->id)));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Updated bank account', type: self::ONE)]
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['code' => 'sometimes|required|string|alpha_dash:ascii|max:32', 'bank_code' => 'sometimes|required|string|alpha_dash:ascii|max:24', 'account_number' => 'sometimes|required|string|regex:/\A[0-9]{6,32}\z/', 'holder_name' => 'sometimes|required|string|max:160', 'active' => 'sometimes|required|boolean']);

        return PaymentData::mutate($request, $data, ['payments.bank.view', 'payments.bank.manage', 'payments.bank.update'], fn () => ApiResponse::success(BankAccounts::save($id, $data, $request->user()->id)));
    }
}
