<?php

namespace App\Modules\Shipping;

interface TrackingProvider
{
    /** @return array{events:list<array{id:string,status:string,at:string}>,retry_after?:int} */
    public function poll(string $trackingNumber): array;
}
