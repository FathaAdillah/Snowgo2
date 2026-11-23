<?php

namespace App\Modules\Customers\Addresses;

use App\Modules\Foundation\Outbox\Outbox;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AddressBook
{
    private static function owned(int $owner): Builder
    {
        return DB::table('addresses')->where('addresses.user_id', $owner)->whereNull('addresses.deleted_at');
    }

    public static function listing(int $owner): Builder
    {
        return self::owned($owner)->join('regions as d', 'd.id', '=', 'addresses.district_id')
            ->select('addresses.public_id as id', 'recipient', 'phone', 'd.code as district_code', 'line1', 'line2', 'postal_code', 'is_default')
            ->orderByDesc('is_default')->orderBy('addresses.id');
    }

    private static function lockOwner(int $owner): void
    {
        DB::table('customer_profiles')->where('user_id', $owner)->lockForUpdate()->first() ?? abort(404);
    }

    /** @param array<string,mixed> $data */
    public function save(int $owner, array $data, ?string $publicId = null): object
    {
        return DB::transaction(function () use ($owner, $data, $publicId) {
            self::lockOwner($owner);
            $current = $publicId === null ? null : (self::owned($owner)->where('public_id', $publicId)->first() ?? abort(404));
            $district = DB::table('regions')->where(['kind' => 'DISTRICT', 'active' => true]);
            $district = isset($data['district_code']) ? $district->where('code', $data['district_code']) : $district->where('id', $current?->district_id);
            $data['district_id'] = $district->sharedLock()->value('id') ?? throw ValidationException::withMessages(['district_code' => 'Select an active Indonesian district.']);
            unset($data['district_code']);
            $data['updated_at'] = now();
            if (($data['is_default'] ?? false) || ($current === null && ! self::owned($owner)->exists())) {
                self::owned($owner)->where('is_default', true)->update(['is_default' => false, 'updated_at' => now()]);
                $data['is_default'] = true;
            } elseif ($current?->is_default && array_key_exists('is_default', $data)) {
                throw ValidationException::withMessages(['is_default' => 'Select another address as default instead.']);
            }
            if ($current === null) {
                $publicId = (string) Str::ulid();
                DB::table('addresses')->insert($data + ['user_id' => $owner, 'public_id' => $publicId]);
            } else {
                self::owned($owner)->where('id', $current->id)->update($data);
            }
            $this->changed($owner, $publicId);

            return self::listing($owner)->where('public_id', $publicId)->firstOrFail();
        });
    }

    public function archive(int $owner, string $id): void
    {
        DB::transaction(function () use ($owner, $id) {
            self::lockOwner($owner);
            $target = self::owned($owner)->where('public_id', $id);
            $address = $target->first() ?? abort(404);
            $target->update(['deleted_at' => now(), 'is_default' => false, 'updated_at' => now()]);
            if ($address->is_default) {
                $oldest = self::owned($owner)->min('id');
                self::owned($owner)->where('id', $oldest)->update(['is_default' => true, 'updated_at' => now()]);
            }
            $this->changed($owner, $id);
        });
    }

    private function changed(int $owner, string $id): void
    {
        app(Outbox::class)->record('AddressChanged', 'customer', $owner, ['address_ref' => $id]);
    }
}
