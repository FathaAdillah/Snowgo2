<?php

namespace App\Modules\Customers\Http;

use App\Modules\Customers\Addresses\AddressBook;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AddressController
{
    private const ONE = 'array{success:bool,message:string,data:'.CustomerResponse::ADDRESS.',meta:array{request_id:string}}';

    public const REQUIRED = [
        'recipient' => 'required|string|max:120', 'phone' => 'required|string|'.CustomerResponse::PHONE,
        'district_code' => 'required|string|max:48', 'line1' => 'required|string|max:255', 'postal_code' => 'required|string|max:12',
    ];

    public const OPTIONAL = ['line2' => 'sometimes|nullable|string|max:255', 'is_default' => 'sometimes|required|boolean'];

    #[Response(200, 'Own addresses', type: CustomerResponse::ADDRESSES)]
    #[Response(403, 'Verified customer session required', type: ApiResponse::ERROR_SCHEMA)]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(ApiInput::PAGE);
        ApiInput::fields($request, $filters);

        return CustomerResponse::page(AddressBook::listing($request->user()->id), $filters);
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(201, 'New address', type: self::ONE)]
    #[Response(403, 'Verified customer session required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function create(Request $request, AddressBook $addresses): JsonResponse
    {
        $data = $request->validate([...self::REQUIRED, ...self::OPTIONAL]);
        ApiInput::fields($request, $data);

        return CustomerResponse::ok($addresses->save($request->user()->id, $data), 201);
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(404, 'Address not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Updated address', type: self::ONE)]
    #[Response(403, 'Verified customer session required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function update(Request $request, AddressBook $addresses, string $id): JsonResponse
    {
        $data = $request->validate([
            'recipient' => 'sometimes|'.self::REQUIRED['recipient'], 'phone' => 'sometimes|'.self::REQUIRED['phone'],
            'district_code' => 'sometimes|'.self::REQUIRED['district_code'], 'line1' => 'sometimes|'.self::REQUIRED['line1'],
            'postal_code' => 'sometimes|'.self::REQUIRED['postal_code'], ...self::OPTIONAL,
        ]);
        ApiInput::fields($request, $data);

        return CustomerResponse::ok($addresses->save($request->user()->id, $data, $id));
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(404, 'Address not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Archived address', type: ApiResponse::ACK_SCHEMA)]
    #[Response(403, 'Verified customer session required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function destroy(Request $request, AddressBook $addresses, string $id): JsonResponse
    {
        ApiInput::fields($request, []);
        $addresses->archive($request->user()->id, $id);

        return CustomerResponse::ok();
    }
}
