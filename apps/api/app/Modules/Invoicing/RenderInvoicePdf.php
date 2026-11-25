<?php

namespace App\Modules\Invoicing;

use App\Modules\Foundation\Files\StagedFile;
use App\Modules\Foundation\Files\StoredFiles;
use Aws\S3\Exception\S3Exception;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RenderInvoicePdf implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $invoice, public readonly string $token)
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $claim = DB::table('invoice_documents')->where(['invoice_id' => $this->invoice, 'token' => $this->token, 'status' => 'RENDERING']);
        $object = (clone $claim)->value('object_id');
        if ($object === null) {
            return;
        }
        $data = InvoiceDirectory::data(InvoiceDirectory::rows(null)->where('i.id', $this->invoice)->firstOrFail());
        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'fontDir' => resource_path('fonts'), 'isFontSubsettingEnabled' => true]);
        $pdf->getDomPDF()->getFontMetrics()->loadFontFamilies();
        $bytes = $pdf->loadView('invoice-pdf', ['invoice' => $data])->setPaper('a4')->output();
        $hash = hash('sha256', $bytes);
        // Owned by the invoice document even before file registration, outside the upload janitor's prefixes.
        $key = "generated/invoice_pdf/$object.pdf";
        $target = ['Bucket' => config('filesystems.disks.private.bucket'), 'Key' => $key];
        $disk = Storage::disk('private');
        if (! $disk instanceof AwsS3V3Adapter) {
            throw new RuntimeException('Private invoice storage must support conditional S3 writes.');
        }
        $client = $disk->getClient();
        $size = strlen($bytes);
        try {
            $client->putObject($target + ['Body' => $bytes, 'IfNoneMatch' => '*', 'ContentType' => 'application/pdf',
                'CacheControl' => 'no-store', 'Metadata' => ['sha256' => $hash]]);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 412) {
                throw $e;
            }
            // A prior worker wrote the immutable object but may have lost its lease before registration.
            $existing = $client->headObject($target);
            $hash = $existing['Metadata']['sha256'] ?? '';
            $size = (int) $existing['ContentLength'];
            if (! preg_match('/\A[0-9a-f]{64}\z/D', $hash) || $size < 1) {
                throw new RuntimeException('Existing invoice object has invalid metadata.');
            }
        }
        DB::transaction(function () use ($claim, $size, $key, $hash, $data) {
            if (! (clone $claim)->lockForUpdate()->first()) {
                return;
            }
            $file = new StagedFile((string) Str::ulid(), $key, 'application/pdf', $size, $hash, $data['number'].'.pdf');
            $id = app(StoredFiles::class)->registerGenerated($file, 'invoice', $this->invoice, 'invoice_pdf');
            $claim->update(['status' => 'READY', 'file_id' => $id, 'token' => null, 'lease_until' => null]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('invoice_documents')->where(['invoice_id' => $this->invoice, 'token' => $this->token])
            ->update(['status' => 'FAILED', 'token' => null, 'lease_until' => null]);
    }
}
