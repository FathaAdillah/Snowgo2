<?php

namespace App\Modules\RequestOrders\Http;

use App\Modules\RequestOrders\CaseData;
use App\Modules\RequestOrders\RequestCases;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffCaseController
{
    #[Response(200, 'Case queue', type: CaseData::STAFF_PAGE)]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([...CaseData::FILTERS, 'number' => 'sometimes|required|string|max:40', 'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d']);

        return $this->applyReadGrants($request, CaseData::page($request, $filters, null));
    }

    #[Response(200, 'Staff case detail', type: CaseData::STAFF_ONE)]
    #[Response(404, 'Request not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, string $number): JsonResponse
    {
        ApiInput::fields($request, []);

        return $this->applyReadGrants($request, CaseData::one($number, null));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Reviewed case', type: CaseData::STAFF_ONE)]
    #[Response(404, 'Request not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'State or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: CaseMutation::INVALID)]
    public function review(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|string|in:START,NEED_INFORMATION,REJECT,REOPEN',
            'assignee_id' => 'sometimes|required|integer|min:1', 'reason_code' => 'sometimes|required|'.CaseMutation::CODE, 'note' => 'sometimes|required|string|max:1000']);
        $actions = [strtolower($data['decision'])];
        if (isset($data['assignee_id'])) {
            $actions[] = 'assign';
        }
        if (isset($data['note'])) {
            $actions[] = 'note';
        }

        $response = CaseMutation::run($request, $data, $number, $actions, function ($case, $actor) use ($data, $number) {
            RequestCases::review($case, $actor, $data);

            return CaseData::one($number, null);
        });

        return $this->applyReadGrants($request, $response);
    }

    /** Apply current grants to the response, including an idempotent review replay. */
    private function applyReadGrants(Request $request, JsonResponse $response): JsonResponse
    {
        $files = $request->user()->can('foundation.file.view');
        $quotes = $request->user()->can('request_orders.quotation.view');
        if ($response->isSuccessful() && (! $files || ! $quotes)) {
            $body = $response->getData();
            foreach (is_array($body->data) ? $body->data : [$body->data] as $row) {
                if (! $files) {
                    $row->files = [];
                }
                if (! $quotes) {
                    $row->quotations = [];
                }
            }
            $response->setData($body);
        }

        return $response;
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Public reply or internal note', type: CaseData::MESSAGE)]
    #[Response(404, 'Request not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'State or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: CaseMutation::INVALID)]
    public function message(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:5000', 'visibility' => 'required|string|in:CUSTOMER,STAFF']);

        return CaseMutation::run($request, $data, $number, [$data['visibility'] === 'STAFF' ? 'note' : 'reply'],
            fn ($case, $actor) => CaseData::message(RequestCases::message($case, $actor, $data['visibility'], $data['body']), true));
    }
}
