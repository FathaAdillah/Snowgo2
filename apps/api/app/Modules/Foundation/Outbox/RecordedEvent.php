<?php

namespace App\Modules\Foundation\Outbox;

final readonly class RecordedEvent
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public int $id,
        public string $uuid,
        public string $type,
        public string $aggregate,
        public int $aggregateId,
        public array $payload,
    ) {}
}
