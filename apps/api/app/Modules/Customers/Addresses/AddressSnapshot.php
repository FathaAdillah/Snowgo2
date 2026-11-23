<?php

namespace App\Modules\Customers\Addresses;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Capture inside the order writer's transaction; Orders persists the returned values, not a live address relation. */
final class AddressSnapshot
{
    /** @return array<string,mixed> */
    public function capture(int $owner, string $publicId): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Capture the address in the order transaction.');
        }
        $row = DB::table('addresses as a')->join('regions as d', 'd.id', '=', 'a.district_id')
            ->join('regions as c', 'c.id', '=', 'd.parent_id')->join('regions as p', 'p.id', '=', 'c.parent_id')
            ->join('regions as n', 'n.id', '=', 'p.parent_id')->where('a.user_id', $owner)
            ->where('a.public_id', $publicId)->whereNull('a.deleted_at')
            ->select('a.id as source_address_id', 'a.recipient', 'a.phone', 'a.line1', 'a.line2', 'a.postal_code', 'a.district_id',
                'd.active', 'd.code as district_code', 'd.name as district_name', 'c.code as city_code', 'c.name as city_name',
                'p.code as province_code', 'p.name as province_name', 'n.code as country_code', 'n.name as country_name')
            ->sharedLock()->first() ?? abort(404);
        if (! $row->active) {
            throw ValidationException::withMessages(['address_id' => 'Select an address with an active district.']);
        }
        $snapshot = (array) $row;
        unset($snapshot['active']);
        foreach (['country', 'province', 'city', 'district'] as $level) {
            $snapshot['region_snapshot'][$level] = ['code' => $snapshot[$level.'_code'], 'name' => $snapshot[$level.'_name']];
            unset($snapshot[$level.'_code'], $snapshot[$level.'_name']);
        }

        return $snapshot;
    }
}
