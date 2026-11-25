<?php

namespace App\Modules\Invoicing\Http;

use App\Modules\Foundation\Files\StoredFiles;
use App\Modules\Invoicing\InvoiceDirectory;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class InvoiceController
{
    #[Response(200, 'Invoices', type: InvoiceDirectory::PAGE)]
    #[Response(403, 'Verified identity and read access required', type: ApiResponse::ERROR_SCHEMA)]
    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate([...ApiInput::PAGE, 'order' => 'sometimes|required|string|max:40', 'status' => 'sometimes|required|string|in:ISSUED,VOID,PAID',
            'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d']);
        ApiInput::fields($request, $data);
        if (isset($data['from'],$data['to']) && $data['to'] < $data['from']) {
            throw ValidationException::withMessages(['to' => 'Must not precede from.']);
        }
        $rows = InvoiceDirectory::rows($owner)->when($data['order'] ?? null, fn ($q, $s) => $q->where('o.number', $s))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('i.status', $s));
        if ($from = $request->date('from', tz: 'Asia/Jakarta')) {
            $rows->where('i.issued_at', '>=', $from->startOfDay()->toIso8601String());
        }
        if ($to = $request->date('to', tz: 'Asia/Jakarta')) {
            $rows->where('i.issued_at', '<', $to->startOfDay()->addDay()->toIso8601String());
        }
        $p = $rows->orderByDesc('i.id')->paginate((int) ($data['per_page'] ?? 20), page: (int) ($data['page'] ?? 1));

        return ApiResponse::success(array_map(InvoiceDirectory::data(...), $p->items()), meta: ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    #[Response(200, 'Invoice snapshot', type: InvoiceDirectory::ONE)]
    #[Response(403, 'Verified identity and read access required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, string $number): JsonResponse
    {
        ApiInput::fields($request, []);

        return self::one($number, $this->owner($request));
    }

    public static function one(string $number, ?int $owner): JsonResponse
    {
        $row = InvoiceDirectory::rows($owner)->where('i.number', $number)->first() ?? abort(404);

        return ApiResponse::success(InvoiceDirectory::data($row))->header('Cache-Control', 'private, no-store');
    }

    #[Response(200, 'Five-minute private URL', type: InvoiceDirectory::DOWNLOAD)]
    #[Response(403, 'Verified identity and download access required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'FILE_NOT_READY', type: ApiResponse::ERROR_SCHEMA)]
    public function pdf(Request $request, string $number, StoredFiles $files): JsonResponse
    {
        ApiInput::fields($request, []);
        $row = InvoiceDirectory::rows($this->owner($request))->where('i.number', $number)->select('i.id', 'd.file_id')->first() ?? abort(404);

        return ApiResponse::success($files->downloadBound($row->file_id, 'invoice', $row->id, 'invoice_pdf', $request->user()->id))->header('Cache-Control', 'private, no-store');
    }

    private function owner(Request $request): ?int
    {
        return $request->routeIs('invoices.staff.*') ? null : $request->user()->id;
    }
}
