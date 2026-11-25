<?php

namespace App\Modules\Invoicing\Http;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Invoicing\InvoiceDirectory;
use App\Modules\Invoicing\InvoiceLifecycle;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InvoiceActions
{
    public const REASON = 'required|string|max:48|regex:/\A[A-Za-z0-9_.-]+\z/D';

    public const INVALID = "array{success:false,message:string,code:'VALIDATION_FAILED'|'IDEMPOTENCY_KEY_REUSED',errors?:array<string,list<string>>,meta:array{request_id:string}}";

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Issued replacement', type: InvoiceDirectory::ONE)]
    #[Response(404, 'Unknown order or source', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: self::INVALID)]
    #[Response(409, 'Invoice not eligible', type: ApiResponse::ERROR_SCHEMA)]
    public function issue(Request $request, string $number): JsonResponse
    {
        return $this->mutate($request, $number, 'issue', $request->validate(['purpose' => 'required|string|in:BALANCE,FULL_PAYMENT,DEPOSIT,ADDITIONAL_CHARGE', 'replaces_invoice_number' => 'sometimes|required|string|max:40', 'due_at' => 'required|date']));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Voided invoice', type: InvoiceDirectory::ONE)]
    #[Response(404, 'Unknown invoice', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: self::INVALID)]
    #[Response(409, 'Invoice not eligible', type: ApiResponse::ERROR_SCHEMA)]
    public function void(Request $request, string $number): JsonResponse
    {
        return $this->mutate($request, $number, 'void', $request->validate(['reason_code' => self::REASON]));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'New invoice with revised due date', type: InvoiceDirectory::ONE)]
    #[Response(404, 'Unknown invoice', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: self::INVALID)]
    #[Response(409, 'Invoice not eligible', type: ApiResponse::ERROR_SCHEMA)]
    public function reviseDue(Request $request, string $number): JsonResponse
    {
        return $this->mutate($request, $number, 'revise_due', $request->validate(['reason_code' => self::REASON, 'due_at' => 'required|date']));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Accepted', type: ApiResponse::ACK_SCHEMA)]
    #[Response(404, 'Unknown invoice', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: self::INVALID)]
    #[Response(409, 'Render is not failed', type: ApiResponse::ERROR_SCHEMA)]
    public function retry(Request $request, string $number): JsonResponse
    {
        return $this->mutate($request, $number, 'retry_pdf', []);
    }

    /** @param array<string,mixed> $data */
    private function mutate(Request $request, string $number, string $action, array $data): JsonResponse
    {
        ApiInput::fields($request, $data);
        $actor = $request->user();
        $authorize = function () use ($actor, $action): string {
            abort_unless($actor->fresh()?->can(['invoicing.invoice.view', 'invoicing.invoice.manage', 'invoicing.invoice.'.$action]), 403);

            return 'user:'.$actor->id;
        };

        return app(IdempotentMutation::class)->run($request, $authorize, function () use ($authorize, $actor, $number, $data, $action) {
            $authorize();
            if ($action === 'retry_pdf') {
                $id = DB::table('invoices')->where('number', $number)->whereNot('status', 'DRAFT')->value('id') ?? abort(404);
                if (! DB::table('invoice_documents')->where(['invoice_id' => $id, 'status' => 'FAILED'])->update(['status' => 'PENDING'])) {
                    return ApiResponse::error('INVALID_STATE_TRANSITION', 'Only failed renders can be retried.', 409);
                }
                app(Outbox::class)->record('InvoicePdfRequested', 'invoice', $id, ['number' => $number]);
                app(Journal::class)->audit('InvoicePdfRequested', Subject::Invoice, $id, $actor->id, ['pdf_status' => 'FAILED'], ['pdf_status' => 'PENDING']);

                return ApiResponse::success();
            }
            try {
                return InvoiceController::one(InvoiceLifecycle::apply($action, $number, $actor->id, $data), null);
            } catch (ApiProblem $e) {
                return $e->render();
            }
        })->header('Cache-Control', 'private, no-store');
    }
}
