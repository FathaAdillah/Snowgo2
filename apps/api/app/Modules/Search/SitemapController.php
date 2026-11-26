<?php

namespace App\Modules\Search;

use App\Modules\Catalog\ProductDirectory;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SitemapController
{
    #[Response(200, 'Published product slugs', type: 'array{success:bool,message:string,data:list<string>,meta:array{request_id:string,last_page:int}}')]
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ApiInput::PAGE['page']]);
        ApiInput::fields($request, $data);
        $page = ProductDirectory::published()->orderBy('id')->paginate(1000, ['slug']);

        return ApiResponse::success($page->getCollection()->pluck('slug')->all(), meta: ['last_page' => $page->lastPage()]);
    }
}
