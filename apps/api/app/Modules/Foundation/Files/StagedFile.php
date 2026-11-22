<?php

namespace App\Modules\Foundation\Files;

final readonly class StagedFile
{
    public function __construct(
        public string $publicId,
        public string $key,
        public string $mime,
        public int $size,
        public string $hash,
        public string $name,
    ) {}
}
