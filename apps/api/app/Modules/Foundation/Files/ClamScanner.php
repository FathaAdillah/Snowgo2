<?php

namespace App\Modules\Foundation\Files;

use RuntimeException;

final class ClamScanner implements MalwareScanner
{
    public function clean(string $bytes): bool
    {
        $socket = @stream_socket_client(config('stored_files.clamd'), timeout: 3);
        if ($socket === false) {
            throw new RuntimeException('ClamAV unavailable');
        }
        try {
            stream_set_timeout($socket, 20);
            $this->write($socket, "zINSTREAM\0");
            for ($offset = 0; $offset < strlen($bytes); $offset += 65536) {
                $chunk = substr($bytes, $offset, 65536);
                $this->write($socket, pack('N', strlen($chunk)).$chunk);
            }
            $this->write($socket, pack('N', 0));
            $reply = '';
            while (strlen($reply) < 4096 && ! str_ends_with($reply, "\0")) {
                $part = fread($socket, 4096);
                if ($part === false || $part === '') {
                    throw new RuntimeException('Incomplete ClamAV response');
                }
                $reply .= $part;
            }

            return match (true) {
                $reply === "stream: OK\0" => true,
                str_starts_with($reply, 'stream: ') && str_ends_with($reply, " FOUND\0") => false,
                default => throw new RuntimeException('ClamAV could not scan the entire file'),
            };
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function write($socket, string $chunk): void
    {
        while ($chunk !== '') {
            $sent = fwrite($socket, $chunk);
            if (! $sent) {
                throw new RuntimeException('ClamAV stream interrupted');
            }
            $chunk = substr($chunk, $sent);
        }
    }
}
