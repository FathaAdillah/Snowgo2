<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Files\FileOwnerPolicy;
use App\Modules\Foundation\Files\FileRules;
use App\Modules\Foundation\Files\StoredFiles;
use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Foundation\Models\StoredFile;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class FilesController
{
    private const FILE_RESPONSE = 'array{success:bool,message:string,data:array{id:string,collection:string,status:string,mime:string,size_bytes:int,created_at:string},meta:array{request_id:string}}';

    private const FILE_ERROR = 'array{success:false,code:string,message:string,meta:array{request_id:string}}';

    public function __construct(private readonly StoredFiles $files) {}

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(202, 'Accepted into private quarantine', type: self::FILE_RESPONSE)]
    #[Response(404, 'Parent not found or inaccessible', type: self::FILE_ERROR)]
    #[Response(409, 'Idempotency conflict', type: self::FILE_ERROR)]
    #[Response(413, 'File too large', type: self::FILE_ERROR)]
    #[Response(415, 'Unsupported content', type: self::FILE_ERROR)]
    #[Response(419, 'CSRF token mismatch', type: self::FILE_ERROR)]
    public function upload(Request $request, IdempotentMutation $guard): JsonResponse
    {
        $data = $request->validate(['owner_type' => 'required|string|max:40', 'owner_ref' => 'required|string|max:120',
            'collection' => 'required|string|max:40', 'file' => 'required|file']);
        if (! in_array($data['owner_type'], FileRules::OWNERS[$data['collection']] ?? [], true)) {
            throw ValidationException::withMessages(['collection' => 'Invalid collection for this parent']);
        }
        abort_if(! $request->routeIs('staff.*') && in_array($data['collection'], FileRules::STAFF_UPLOAD_ONLY, true), 403);
        $actor = $request->user();
        $policy = $this->policy($data['owner_type']);
        $parent = function () use ($actor, $request, $policy, $data): int {
            $id = $policy->forUpload($actor, $request->routeIs('staff.*'), $data['owner_ref'], $data['collection']);
            abort_unless($id !== null && $id > 0, 404);

            return $id;
        };
        $response = $guard->runPrepared($request, function () use ($actor, $parent) {
            $parent();

            return 'user:'.$actor->id;
        }, function () use ($data, $actor, $parent) {
            $this->files->checkQuota($data['owner_type'], $parent(), $data['collection'], (string) $data['file']->getMimeType());
            $staged = $this->files->stage($data['file'], $data['collection']);

            return function () use ($staged, $data, $actor, $parent) {
                $row = $this->files->register($staged, $data['owner_type'], $parent(), $data['collection'], $actor->id);

                return ApiResponse::success($this->metadata($row), status: 202);
            };
        });

        return $response->header('Cache-Control', 'private, no-store');
    }

    #[Response(200, 'Current scan status', type: self::FILE_RESPONSE)]
    #[Response(404, 'File not found or inaccessible', type: self::FILE_ERROR)]
    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->metadata($this->visible($request, $id)))->header('Cache-Control', 'private, no-store');
    }

    #[Response(200, 'Private GET URL, valid for five minutes', type: 'array{success:bool,message:string,data:array{url:string,expires_at:string},meta:array{request_id:string}}')]
    #[Response(404, 'File not found or inaccessible', type: self::FILE_ERROR)]
    #[Response(409, 'File not ready', type: self::FILE_ERROR)]
    public function download(Request $request, string $id): JsonResponse
    {
        $file = $this->visible($request, $id);

        return ApiResponse::success($this->files->download($file, $request->user()->id))->header('Cache-Control', 'private, no-store');
    }

    private function visible(Request $request, string $id): StoredFile
    {
        $row = StoredFile::query()->where('public_id', $id)->whereNot('status', 'DELETED')->first();
        abort_unless($row && $this->policy($row->owner_type)->canView($request->user(), $request->routeIs('staff.*'), $row->owner_id, $row->collection), 404);

        return $row;
    }

    private function policy(string $type): FileOwnerPolicy
    {
        $class = config('stored_files.owners')[$type] ?? null;
        abort_unless(is_string($class) && is_subclass_of($class, FileOwnerPolicy::class), 404);

        return app($class);
    }

    /** @return array<string,mixed> */
    private function metadata(StoredFile $row): array
    {
        return ['id' => $row->public_id, 'collection' => $row->collection, 'status' => $row->status,
            'mime' => $row->mime, 'size_bytes' => $row->size_bytes, 'created_at' => Carbon::parse($row->created_at)->toISOString()];
    }
}
