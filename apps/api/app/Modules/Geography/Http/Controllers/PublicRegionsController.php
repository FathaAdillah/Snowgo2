<?php

namespace App\Modules\Geography\Http\Controllers;

use App\Modules\Geography\Master;
use App\Modules\Geography\References;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicRegionsController
{
    #[Response(200, 'Active delivery regions', type: "array{success:bool,message:string,data:list<array{code:string,name:string,kind:'COUNTRY'|'PROVINCE'|'CITY'|'DISTRICT',parent_code:string|null}>,meta:array{request_id:string,page:int,last_page:int,per_page:int,total:int}}")]
    public function __invoke(Request $request, References $refs): JsonResponse
    {
        return $refs->page($request, Master::Region, $request->validate([
            ...References::PAGE, 'parent' => 'sometimes|required|string|max:48|'.References::CODE,
            'kind' => 'sometimes|required|string|'.References::REGION_KINDS,
        ]), public: true);
    }
}
