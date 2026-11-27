<?php

namespace App\Modules\Notifications\Http;

use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

final class InboxController
{
    private const ITEM = 'array{id:string,template:string,title:string,body:string,link:string,read_at:string|null,created_at:string}';

    #[Response(200, type: 'array{success:bool,message:string,data:list<'.self::ITEM.'>,meta:array{request_id:string,next_cursor:string|null}}')]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cursor' => 'sometimes|string|max:512',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'unread' => 'sometimes|string|in:0,1,true,false',
        ]);
        ApiInput::fields($request, $data);
        $cursor = isset($data['cursor']) ? Cursor::fromEncoded($data['cursor']) : null;
        $publicId = $cursor?->toArray()['public_id'] ?? null;
        if (isset($data['cursor']) && (! is_string($publicId) || ! preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $publicId))) {
            throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        }

        $inbox = $this->inbox($request);
        if ($request->boolean('unread')) {
            $inbox->whereNull('read_at');
        }
        $page = $inbox->orderByDesc('public_id')->cursorPaginate($data['per_page'] ?? 20, cursor: $cursor);

        return ApiResponse::success(array_map($this->item(...), $page->items()), meta: ['next_cursor' => $page->nextCursor()?->encode()]);
    }

    #[Response(200, type: 'array{success:bool,message:string,data:'.self::ITEM.',meta:array{request_id:string}}')]
    #[Response(404, type: ApiResponse::ERROR_SCHEMA)]
    public function read(Request $request, string $id): JsonResponse
    {
        ApiInput::fields($request, []);
        $entry = $this->inbox($request)->where('public_id', $id);
        (clone $entry)->whereNull('read_at')->update(['read_at' => now()]);
        $item = $entry->first();
        if ($item === null) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Notification not found.');
        }

        return ApiResponse::success($this->item($item));
    }

    private function inbox(Request $request): Builder
    {
        return DB::table('notifications')->where('user_id', $request->user()->id)
            ->select('public_id', 'template', 'payload', 'read_at', 'created_at');
    }

    /** @return array{id:string,template:string,title:string,body:string,link:string,read_at:?string,created_at:string} */
    private function item(stdClass $row): array
    {
        $message = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => $row->public_id,
            'template' => $row->template,
            'title' => $message['title'],
            'body' => $message['body'],
            'link' => $message['link'],
            'read_at' => $row->read_at === null ? null : Carbon::parse($row->read_at)->toISOString(),
            'created_at' => Carbon::parse($row->created_at)->toISOString(),
        ];
    }
}
