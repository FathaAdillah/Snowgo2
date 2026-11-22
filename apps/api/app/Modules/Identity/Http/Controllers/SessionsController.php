<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SessionsController
{
    private const PAGE = 'array{success:bool,message:string,data:array<int,array{session_ref:string,device_label:string,last_active_at:string,current:bool}>,meta:array{request_id:string,page:int,per_page:int,total:int}}';

    public function __construct(private readonly DeviceSessions $sessions) {}

    /**
     * Active devices of the signed-in account on the current surface.
     */
    #[Response(200, 'Active sessions', type: self::PAGE)]
    #[Response(401, 'Unauthenticated', type: AuthController::ERROR)]
    public function index(Request $request): JsonResponse
    {
        $query = $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|min:1|max:50']);
        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 20);
        $surface = DeviceSessions::surface($request);
        assert($surface !== null && $request->user() instanceof User);
        $result = $this->sessions->page($request->user(), $surface, $request->session()->getId(), $page, $perPage);

        return ApiResponse::success($result['items'], meta: ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']])
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Revoke one of the account's own devices on the same surface.
     */
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Session revoked', type: AuthController::ACK)]
    #[Response(401, 'Unauthenticated', type: AuthController::ERROR)]
    #[Response(404, 'Not found', type: AuthController::ERROR)]
    public function destroy(Request $request, string $sessionRef): JsonResponse
    {
        $surface = DeviceSessions::surface($request);
        assert($surface !== null && $request->user() instanceof User);
        $id = $this->sessions->revoke($request->user(), $surface, $sessionRef);
        abort_if($id === null, 404);
        if ($id === $request->session()->getId()) {
            $this->sessions->end($request);
        }

        return ApiResponse::success(null, 'Session revoked');
    }
}
