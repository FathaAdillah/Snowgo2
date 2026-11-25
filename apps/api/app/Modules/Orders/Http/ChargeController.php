<?php

namespace App\Modules\Orders\Http;

use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Invoicing\BalanceInvoices;
use App\Modules\Invoicing\Http\InvoiceController;
use App\Modules\Invoicing\InvoiceDirectory;
use App\Modules\Orders\ChargeConsents;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChargeController
{
    public const ONE = 'array{success:bool,message:string,data:array{id:string,status:string,revision:int,amount:int,expires_at:string,invoice_number?:string,lines:list<array{type:string,description:string,qty:int,unit_amount:int,amount:int,order_item_id?:string,start?:int}>},meta:array{request_id:string}}';

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function propose(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['type' => 'required|string|in:DOMESTIC_SHIPPING,IMPORT_TAX,INTERNATIONAL_SHIPPING,SERVICE_FEE', 'quote_id' => 'required_if:type,DOMESTIC_SHIPPING|prohibited_unless:type,DOMESTIC_SHIPPING|string|ulid',
            'order_item_id' => 'required_unless:type,DOMESTIC_SHIPPING|prohibited_if:type,DOMESTIC_SHIPPING|string|ulid', 'qty' => 'required_unless:type,DOMESTIC_SHIPPING|prohibited_if:type,DOMESTIC_SHIPPING|integer|between:1,100000',
            'unit_amount' => 'required_unless:type,DOMESTIC_SHIPPING|prohibited_if:type,DOMESTIC_SHIPPING|integer|between:1,1000000000000', 'description' => 'required_unless:type,DOMESTIC_SHIPPING|prohibited_if:type,DOMESTIC_SHIPPING|string|max:200', 'expires_at' => 'required|date|after:now']);

        return $this->run($request, $number, $data, ['orders.order.view', 'orders.order.manage', 'orders.charge.propose'], fn ($order, $actor) => ApiResponse::success(ChargeConsents::present(ChargeConsents::propose($order, $actor, $data))));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function decide(Request $request, string $number, string $id): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|string|in:ACCEPT,REJECT']);

        return $this->run($request, $number, $data, [], function ($order, $actor) use ($id, $data) {
            ChargeConsents::decide($order, $id, $actor, $data['decision']);

            return ApiResponse::success(ChargeConsents::present($id));
        });
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: InvoiceDirectory::ONE)]
    public function correct(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['order_item_id' => 'required|string|ulid', 'invoice_number' => 'required|string|max:40', 'reason_code' => 'required|string|max:48']);

        return $this->run($request, $number, $data, ['invoicing.invoice.view', 'invoicing.invoice.manage', 'invoicing.invoice.credit_adjust'], fn ($order, $actor) => InvoiceController::one(BalanceInvoices::correct($order, $actor, $data), null));
    }

    /** @param array<string,mixed> $data
     * @param list<string> $abilities */
    private function run(Request $request, string $number, array $data, array $abilities, Closure $command): JsonResponse
    {
        ApiInput::fields($request, $data);
        $actor = $request->user();
        $auth = function () use ($actor, $abilities) {
            abort_if($abilities && ! $actor->fresh()?->can($abilities), 403);

            return 'user:'.$actor->id;
        };

        return app(IdempotentMutation::class)->run($request, $auth, function () use ($auth, $actor, $abilities, $number, $command) {
            $auth();
            $order = DB::table('orders')->where('number', $number)->when(! $abilities, fn ($q) => $q->where('user_id', $actor->id))->lockForUpdate()->first() ?? abort(404);

            return $command($order, $actor->id);
        })->header('Cache-Control', 'private, no-store');
    }
}
