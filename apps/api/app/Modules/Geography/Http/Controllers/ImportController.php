<?php

namespace App\Modules\Geography\Http\Controllers;

use App\Modules\Foundation\Files\StoredFiles;
use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Geography\References;
use App\Modules\Geography\RegionBatch;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ImportController
{
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(202, 'Import accepted', type: ApiResponse::ACK_SCHEMA)]
    #[Response(404, 'File not found or inaccessible', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'File not READY or idempotency conflict', type: ApiResponse::ERROR_SCHEMA)]
    public function __invoke(Request $request, StoredFiles $files, IdempotentMutation $guard): JsonResponse
    {
        $data = $request->validate(['file_id' => 'required|string|ulid']);
        References::fields($request, $data);
        $actor = $request->user();
        $ready = fn () => $files->requireReady($data['file_id'], 'staging', $actor->id, 'region_import');

        return $guard->runPrepared($request, function () use ($actor) {
            abort_unless($actor->can('geography.region.import'), 403);

            return 'user:'.$actor->id;
        }, function () use ($ready, $actor) {
            $file = DB::transaction($ready);
            $stream = Storage::disk('private')->readStream($file->object_key);
            if (! is_resource($stream)) {
                throw new RuntimeException('Scanned CSV unavailable.');
            }
            try {
                $batch = RegionBatch::csv($stream);
            } finally {
                fclose($stream);
            }

            return function () use ($ready, $file, $actor, $batch) {
                abort_unless($actor->fresh()?->can('geography.region.import'), 403);
                abort_unless($ready()->object_key === $file->object_key, 409);
                $batch->import($actor->id);

                return ApiResponse::success(status: 202);
            };
        });
    }
}
