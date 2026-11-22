<?php

namespace App\Modules\Foundation\Files;

use finfo;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class FileRules
{
    public const OWNERS = [
        'catalog_media' => ['product', 'variant'], 'payment_proof' => ['payment'],
        'request_attachment' => ['request_order'], 'return_evidence' => ['return'],
        'refund_evidence' => ['refund'], 'refund_transfer_proof' => ['refund'],
        'reconciliation_proof' => ['payment_reconciliation'], 'shipment_attachment' => ['shipment'],
        'region_import' => ['staging'],
    ];

    public const STAFF_UPLOAD_ONLY = ['catalog_media', 'region_import', 'refund_transfer_proof', 'reconciliation_proof', 'shipment_attachment'];

    private const EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp'],
        'application/pdf' => ['pdf'], 'video/mp4' => ['mp4'], 'text/csv' => ['csv'],
    ];

    public static function inspect(UploadedFile $file, string $collection): string
    {
        if (! $file->isValid() || ! isset(self::OWNERS[$collection])) {
            throw new FileProblem(415, 'UPLOAD_REJECTED', 'Invalid upload');
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if ($collection === 'region_import' && in_array($mime, ['text/plain', 'text/csv'], true)) {
            $mime = 'text/csv';
        }
        $image = str_starts_with($mime, 'image/');
        $limit = match (true) {
            $collection === 'region_import' && $mime === 'text/csv' => 5,
            $collection === 'region_import' => 0,
            $collection === 'catalog_media' => $image ? 8 : 0,
            $collection === 'return_evidence' && $mime === 'video/mp4' => 20,
            $image || $mime === 'application/pdf' => 10,
            default => 0,
        };
        if (! $limit || ! in_array(strtolower($file->getClientOriginalExtension()), self::EXTENSIONS[$mime] ?? [], true)) {
            throw new FileProblem(415, 'UPLOAD_REJECTED', 'Unsupported file content or extension');
        }
        if ($file->getSize() < 1 || $file->getSize() > $limit * 1048576) {
            throw new FileProblem(413, 'UPLOAD_REJECTED', 'File exceeds collection size limit');
        }
        $path = $file->getPathname();
        $header = file_get_contents($path, length: 16);
        if ($image) {
            $info = @getimagesize($path);
            $valid = $info && $info['mime'] === $mime && $info[0] * $info[1] <= 12000000;
        } else {
            $valid = match ($mime) {
                'application/pdf' => str_starts_with((string) $header, '%PDF-')
                    && str_contains((string) file_get_contents($path, offset: max(0, $file->getSize() - 1024)), '%%EOF'),
                'video/mp4' => substr((string) $header, 4, 4) === 'ftyp',
                'text/csv' => ! str_contains((string) file_get_contents($path), "\0"),
                default => false,
            };
        }
        if (! $valid) {
            throw new FileProblem(415, 'UPLOAD_REJECTED', 'Invalid file structure');
        }

        return $mime;
    }

    public static function validContainer(string $mime, string $bytes): bool
    {
        if ($mime !== 'application/pdf' && $mime !== 'video/mp4') {
            return true;
        }
        $stream = tmpfile();
        if (! $stream) {
            throw new RuntimeException('Cannot create decoder input');
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream)) {
                throw new RuntimeException('Cannot stage decoder input');
            }
            $path = stream_get_meta_data($stream)['uri'];
            $process = $mime === 'application/pdf'
                ? (new Process(['qpdf', '--check', $path]))->disableOutput()
                : new Process(['ffmpeg', '-v', 'quiet', '-nostdin', '-xerror', '-f', 'mov', '-enable_drefs', '0',
                    '-protocol_whitelist', 'file', '-max_pixels', '12000000', '-threads', '1', '-i', $path,
                    '-map', '0:V:0', '-frames:v', '1', '-vf', 'scale=1:1', '-pix_fmt', 'gray', '-f', 'rawvideo', 'pipe:1']);
            $exit = $process->setTimeout(10)->run();
            if ($exit === 127) {
                throw new RuntimeException('File decoder unavailable');
            }

            return $exit === 0 && ($mime === 'application/pdf' || strlen($process->getOutput()) === 1);
        } catch (ProcessTimedOutException) {
            return false;
        } finally {
            fclose($stream);
        }
    }
}
