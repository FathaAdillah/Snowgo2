<?php

namespace App\Modules\RequestOrders\Http;

use App\Modules\RequestOrders\CaseData;
use App\Modules\RequestOrders\RequestCases;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CaseController
{
    #[Response(200, 'Own requests', type: CaseData::PAGE)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    public function index(Request $request): JsonResponse
    {
        return CaseData::page($request, $request->validate(CaseData::FILTERS), $request->user()->id);
    }

    #[Response(200, 'Customer-visible case thread', type: CaseData::ONE)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Request not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, string $number): JsonResponse
    {
        ApiInput::fields($request, []);

        return CaseData::one($number, $request->user()->id);
    }

    #[IgnoreResponse(200)]
    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(201, 'Request submitted', type: CaseData::SUMMARY)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Request in progress', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: CaseMutation::INVALID)]
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => 'required|string|max:160', 'description' => 'required|string|max:5000',
            'budget_amount' => 'sometimes|required|integer|min:0|max:1000000000000', 'target_date' => 'sometimes|required|date_format:Y-m-d',
            'items' => 'required|array|list|min:1|max:200', 'items.*' => 'required|array:name,source_url,options,qty,notes',
            'items.*.name' => 'required|string|max:200', 'items.*.qty' => 'required|integer|min:1|max:10000',
            'items.*.source_url' => 'sometimes|required|string|max:2048|url:http,https', 'items.*.options' => 'sometimes|required|string|max:1000',
            'items.*.notes' => 'sometimes|required|string|max:1000']);
        foreach ($data['items'] as $i => $item) {
            foreach ($item as $value) {
                if (is_string($value) && str_contains($value, "\0")) {
                    throw ValidationException::withMessages(["items.$i" => 'Invalid text.']);
                }
            }
        }

        return CaseMutation::run($request, $data, null, null, fn ($case, $actor) => CaseData::one(RequestCases::submit($actor, $data)->number, $actor, false, 201));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Customer message', type: CaseData::MESSAGE)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Request not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'State or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: CaseMutation::INVALID)]
    public function message(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:5000']);

        return CaseMutation::run($request, $data, $number, null, fn ($case, $actor) => CaseData::message(RequestCases::message($case, $actor, 'CUSTOMER', $data['body']), false));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Request cancelled', type: CaseData::SUMMARY)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Request not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'State or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: CaseMutation::INVALID)]
    public function cancel(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['reason_code' => 'required|'.CaseMutation::CODE]);

        return CaseMutation::run($request, $data, $number, null, function ($case, $actor) use ($data, $number) {
            RequestCases::cancel($case, $actor, $data['reason_code']);

            return CaseData::one($number, $actor, false);
        });
    }
}
