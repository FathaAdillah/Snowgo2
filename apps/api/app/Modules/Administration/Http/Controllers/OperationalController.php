<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use App\Support\Http\CaseResponses;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OperationalController
{
    private const SETTING = 'array{key:string,value:int,minimum:int,maximum:int,revision:int}';

    private const ONE = 'array{success:bool,message:string,data:'.self::SETTING.',meta:array{request_id:string}}';

    #[Response(200, type: 'array{success:bool,message:string,data:list<array{id:int,action:string,actor_id:int|null,subject_type:string,subject_ref:int|null,request_id:string,occurred_at:string}>,meta:array{request_id:string,next_cursor:string|null}}')]
    public function audit(Request $request): JsonResponse
    {
        $d = $request->validate(['cursor' => 'sometimes|string|max:512', 'per_page' => 'sometimes|integer|between:1,100', 'actor_id' => 'sometimes|integer|min:1',
            'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d', 'action' => 'sometimes|string|max:160', 'subject_type' => 'sometimes|string|max:40']);
        ApiInput::fields($request, $d);
        $cursor = isset($d['cursor']) ? Cursor::fromEncoded($d['cursor']) : null;
        if (isset($d['cursor']) && (! $cursor || ! is_numeric($cursor->toArray()['id'] ?? null))) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        }
        $rows = DB::table('audit_logs')->select('id', 'action', 'actor_id', 'subject_type', 'subject_id as subject_ref', 'request_id', 'occurred_at');
        foreach (['actor_id', 'action', 'subject_type'] as $key) {
            if (isset($d[$key])) {
                $rows->where($key, $d[$key]);
            }
        }
        if ($from = $request->date('from', tz: 'Asia/Jakarta')) {
            $rows->where('occurred_at', '>=', $from->toISOString());
        }
        if ($to = $request->date('to', tz: 'Asia/Jakarta')) {
            $rows->where('occurred_at', '<', $to->addDay()->toISOString());
        }
        $page = $rows->orderByDesc('id')->cursorPaginate($d['per_page'] ?? 20, cursor: $cursor);
        foreach ($page->items() as $row) {
            $row->occurred_at = Carbon::parse($row->occurred_at)->toISOString();
        }

        return ApiResponse::success($page->items(), meta: ['next_cursor' => $page->nextCursor()?->encode()]);
    }

    #[Response(200, type: 'array{success:bool,message:string,data:list<'.self::SETTING.'>,meta:array{request_id:string,page:int,per_page:int,total:int,last_page:int}}')]
    public function settings(Request $request): JsonResponse
    {
        $d = $request->validate([...ApiInput::PAGE, 'q' => 'sometimes|required|string|max:120']);
        ApiInput::fields($request, $d);
        $p = DB::table('settings')->select('key', 'value', 'minimum', 'maximum', 'revision')->when($d['q'] ?? null, fn ($q, $v) => $q->where('key', 'like', '%'.$v.'%'))->orderBy('key')->paginate($d['per_page'] ?? 20);

        return ApiResponse::success($p->items(), meta: ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]);
    }

    #[Response(200, type: self::ONE)]
    public function show(Request $request, string $key): JsonResponse
    {
        ApiInput::fields($request, []);

        return ApiResponse::success(DB::table('settings')->where('key', $key)->first(['key', 'value', 'minimum', 'maximum', 'revision']) ?? abort(404));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function update(Request $request, string $key): JsonResponse
    {
        $d = $request->validate(['value' => 'required|integer', 'revision' => 'sometimes|integer|min:0']);

        return CaseResponses::write($request, $d, function () use ($key, $d, $request) {
            $r = DB::table('settings')->where('key', $key)->lockForUpdate()->first() ?? abort(404);
            if ($d['value'] < $r->minimum || $d['value'] > $r->maximum) {
                throw ValidationException::withMessages(['value' => "Value must be between $r->minimum and $r->maximum."]);
            }
            if (isset($d['revision']) && $r->revision !== (int) $d['revision']) {
                throw new ApiProblem(409, 'STALE_VERSION', 'Reload this setting before editing.');
            }
            DB::table('settings')->where('id', $r->id)->update(['value' => $d['value'], 'revision' => $r->revision + 1]);
            app(Journal::class)->audit('SettingChanged', Subject::Setting, $r->id, $request->user()->id, ['key' => $key, 'value' => $r->value], ['key' => $key, 'value' => $d['value']]);
            app(Outbox::class)->record('SettingChanged', 'setting', $r->id, ['key' => $key, 'revision' => $r->revision + 1]);

            return ['key' => $key, 'value' => (int) $d['value'], 'minimum' => $r->minimum, 'maximum' => $r->maximum, 'revision' => $r->revision + 1];
        });
    }
}
