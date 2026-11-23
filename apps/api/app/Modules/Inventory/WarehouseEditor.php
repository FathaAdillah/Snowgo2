<?php

namespace App\Modules\Inventory;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WarehouseEditor
{
    /** @param array<string,mixed> $input */
    public function save(array $input, int $actor, ?int $id): int
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtextextended('inventory:warehouses',0))");
        $current = $id === null ? null : (DB::table('warehouses')->where('id', $id)->lockForUpdate()->first() ?? abort(404));
        $active = (bool) ($input['active'] ?? $current?->active);
        if ($id !== null && ! $active && DB::table('inventory_levels')->where('warehouse_id', $id)->where('on_hand', '>', 0)->exists()) {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'A warehouse with stock cannot be archived.');
        }
        if (isset($input['district_code']) || $active) {
            $district = DB::table('regions')->where(['active' => true, 'kind' => 'DISTRICT']);
            $district = isset($input['district_code']) ? $district->where('code', $input['district_code']) : $district->where('id', $current?->district_id);
            $input['district_id'] = $district->sharedLock()->value('id') ?? throw ValidationException::withMessages(['district_code' => 'Select an active district.']);
            unset($input['district_code']);
        }

        $others = DB::table('warehouses')->where('active', true)->when($id !== null, fn ($q) => $q->where('id', '<>', $id));
        if (! $active && ($input['is_default'] ?? false)) {
            throw ValidationException::withMessages(['is_default' => 'An inactive warehouse cannot be the default.']);
        }
        $default = $active && ($input['is_default'] ?? $current?->is_default);
        if ($active && ! (clone $others)->exists()) {
            $default = true;
        }
        if ($current?->is_default && ! $default && (clone $others)->exists()) {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Select another default warehouse first.');
        }
        if ($default && ($previous = (clone $others)->where('is_default', true)->first())) {
            if ($id === null) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Create a non-default warehouse, then promote it with update permission.');
            }
            DB::table('warehouses')->where('id', $previous->id)->update(['is_default' => false]);
            $this->changed($previous->id, $actor, (array) $previous, ['is_default' => false]);
        }
        $input['is_default'] = $default;
        if ($current) {
            DB::table('warehouses')->where('id', $id)->update($input);
        } else {
            $id = DB::table('warehouses')->insertGetId($input);
        }
        $this->changed($id, $actor, $current ? (array) $current : null, $input);

        return $id;
    }

    /** @param array<string,mixed>|null $before
     * @param array<string,mixed> $after */
    private function changed(int $id, int $actor, ?array $before, array $after): void
    {
        app(Journal::class)->audit('WarehouseChanged', Subject::Warehouse, $id, $actor, $before, $after);
        app(Outbox::class)->record('WarehouseChanged', 'warehouse', $id, []);
    }
}
