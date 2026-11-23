<?php

namespace App\Modules\Customers\Http;

use App\Models\User;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProfileController
{
    private const PROFILE = 'array{success:bool,message:string,data:array{display_name:string,email:string,phone:string|null,marketing_opt_in:bool},meta:array{request_id:string}}';

    private function read(User $user): JsonResponse
    {
        $row = DB::table('customer_profiles')->where('user_id', $user->id)->first(['display_name', 'phone', 'marketing_opt_in']) ?? abort(404);

        return CustomerResponse::ok(['email' => $user->email] + (array) $row);
    }

    #[Response(200, 'Own profile', type: self::PROFILE)]
    #[Response(403, 'Verified customer session required', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request): JsonResponse
    {
        ApiInput::fields($request, []);

        return $this->read($request->user());
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Updated profile', type: self::PROFILE)]
    #[Response(403, 'Verified customer session required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function update(Request $request, Outbox $outbox): JsonResponse
    {
        $data = $request->validate(['display_name' => 'sometimes|required|string|max:120', 'phone' => 'sometimes|nullable|string|'.CustomerResponse::PHONE,
            'marketing_opt_in' => 'sometimes|required|boolean']);
        ApiInput::fields($request, $data);
        $user = $request->user();

        return DB::transaction(function () use ($data, $user, $outbox) {
            DB::table('customer_profiles')->where('user_id', $user->id)->update($data + ['updated_at' => now()]);
            $outbox->record('ProfileUpdated', 'customer', $user->id, []);

            return $this->read($user);
        });
    }
}
