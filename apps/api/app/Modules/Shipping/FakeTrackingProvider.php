<?php

namespace App\Modules\Shipping;

final readonly class FakeTrackingProvider implements TrackingProvider
{
    /** @param array<string,array{events:list<array{id:string,status:string,at:string}>,retry_after?:int}> $responses */
    public function __construct(private array $responses) {}

    public function poll(string $trackingNumber): array
    {
        return $this->responses[$trackingNumber] ?? ['events' => []];
    }
}
