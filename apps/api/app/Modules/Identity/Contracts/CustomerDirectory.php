<?php

namespace App\Modules\Identity\Contracts;

/**
 * Implemented by the Customers module, which owns profile data.
 */
interface CustomerDirectory
{
    /** Create the profile of a new customer inside the registration transaction. */
    public function open(int $userId, string $displayName): void;

    public function displayName(int $userId): ?string;
}
