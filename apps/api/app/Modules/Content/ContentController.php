<?php

namespace App\Modules\Content;

use App\Modules\Catalog\CatalogAdmin;
use App\Modules\Foundation\Http\CaseResponses;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContentController
{
    public const ENTRY = 'array{id:int,slug:string,type:string,title:string,body:string,status:string,sort_order:int,version:int,publish_at:string|null,media_id:string|null}';

    public const ONE = 'array{success:bool,message:string,data:'.self::ENTRY.',meta:array{request_id:string}}';

    public const LIST = 'array{success:bool,message:string,data:list<'.self::ENTRY.'>,meta:array{request_id:string,page:int,last_page:int,total:int,per_page:int}}';

    public const RULES = ['slug' => 'sometimes|required|string|max:160|'.CatalogAdmin::SLUG, 'type' => 'sometimes|required|string|in:PAGE,FAQ,BANNER,FEATURED', 'title' => 'sometimes|required|string|max:160', 'body' => 'sometimes|required|string|max:20000', 'status' => 'sometimes|required|string|in:DRAFT,PUBLISHED,ARCHIVED', 'sort_order' => 'sometimes|integer|min:0|max:100000', 'publish_at' => 'nullable|date', 'media_id' => 'nullable|string|ulid'];

    #[Response(200, 'Published navigation and homepage entries', type: 'array{success:bool,message:string,data:list<array{slug:string,type:string,title:string,media_url:string|null}>,meta:array{request_id:string,page:int,last_page:int}}')]
    public function directory(Request $request): JsonResponse
    {
        $filters = $request->validate(ApiInput::PAGE);
        ApiInput::fields($request, $filters);
        $effective = DB::table('content_publications')->selectRaw('DISTINCT ON (entry_id) entry_id, snapshot')
            ->where('effective_at', '<=', now())->orderBy('entry_id')->orderByDesc('version');
        $page = DB::query()->fromSub($effective, 'effective')
            ->orderByRaw("(snapshot->>'sort_order')::int, entry_id")->paginate((int) ($filters['per_page'] ?? 50));
        $entries = $page->getCollection()->map(function ($row) {
            $entry = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);

            return array_intersect_key($entry,array_flip(['slug','type','title'])) + ['media_url'=>ContentEntries::mediaUrl($entry['media_id'])];
        });

        return ApiResponse::success($entries, meta: ['page'=>$page->currentPage(), 'last_page'=>$page->lastPage()]);
    }

    #[Response(200, 'Effective publication', type: 'array{success:bool,message:string,data:array{slug:string,type:string,title:string,body:string,media_url:string|null},meta:array{request_id:string}}')]
    public function published(string $slug): JsonResponse
    {
        $snapshot = DB::table('content_publications as p')->join('content_entries as e', 'e.id', '=', 'p.entry_id')->where('e.slug', $slug)->where('p.effective_at', '<=', now())->orderByDesc('p.version')->value('p.snapshot') ?? abort(404);
        $entry = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);

        return ApiResponse::success(array_intersect_key($entry, array_flip(['slug', 'type', 'title', 'body'])) + ['media_url' => ContentEntries::mediaUrl($entry['media_id'])]);
    }

    #[Response(200, 'Content entries', type: self::LIST)]
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([...ApiInput::PAGE, 'q' => 'sometimes|string|max:160']);
        ApiInput::fields($request, $d);
        $page = DB::table('content_entries')->when($d['q'] ?? null, fn ($q, $text) => $q->whereLike('title', '%'.$text.'%'))->orderBy('sort_order')->orderBy('id')->paginate((int) ($d['per_page'] ?? 20));

        return ApiResponse::success($page->items(), meta: ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]);
    }

    #[Response(200, 'Draft preview', type: self::ONE)]
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(DB::table('content_entries')->find($id) ?? abort(404));
    }

    #[Response(201, 'Created', type: self::ONE)]
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([...self::RULES, 'slug' => 'required|string|max:160|'.CatalogAdmin::SLUG, 'title' => 'required|string|max:160', 'type' => 'required|string|in:PAGE,FAQ,BANNER,FEATURED', 'body' => 'required|string|max:20000']);

        return CaseResponses::write($request, $data, fn () => ContentEntries::save($request->user(), null, $data), 201);
    }

    #[Response(200, 'Updated', type: self::ONE)]
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([...self::RULES, 'version' => 'required|integer|min:1']);

        return CaseResponses::write($request, $data, fn () => ContentEntries::save($request->user(),$id,$data));
    }
}
