<?php

namespace App\Modules\Shipping;

/** Unwind the pricing transaction before contacting the provider, then revalidate the full operation. */
final class FreightLookup extends \RuntimeException
{
    public function __construct(public readonly string $origin, public readonly string $destination, public readonly int $grams) {}
}
