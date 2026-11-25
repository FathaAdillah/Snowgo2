<?php

namespace App\Modules\Preorders;

use App\Modules\Catalog\ProductDirectory;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CampaignEditor
{
    public const ETA = ['ready_eta_from', 'ready_eta_to', 'ship_eta_from', 'ship_eta_to'];

    /** @param array<string,mixed> $input */
    public function save(?string $number, array $input, int $actor): string
    {
        $campaigns = DB::table('preorder_campaigns');
        $prior = $number === null ? null : (clone $campaigns)->where('number', $number)->lockForUpdate()->first() ?? abort(404);
        $fields = Arr::except($input, ['variants']);
        $next = $fields + (array) $prior;
        $start = Carbon::parse($next['starts_at']);
        $end = Carbon::parse($next['ends_at']);
        if ($end->lte($start) || $next['ready_eta_from'] > $next['ready_eta_to'] || $next['ship_eta_from'] > $next['ship_eta_to']) {
            throw ValidationException::withMessages(['dates' => 'Use an ordered sale window and ETA ranges.']);
        }
        if ($prior && (in_array($prior->status, ['COMPLETED', 'CANCELLED'], true)
            || ($prior->status !== 'DRAFT' && $next['slug'] !== $prior->slug)
            || (! in_array($prior->status, ['DRAFT', 'SCHEDULED', 'OPEN'], true) && array_diff(array_keys($input), self::ETA))
            || ($prior->status === 'OPEN' && (! $start->equalTo($prior->starts_at) || (isset($fields['ends_at']) &&
                ($end->lt($prior->ends_at) || DB::scalar('SELECT ?::timestamptz <= clock_timestamp()', [$prior->ends_at]))))))) {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'These fields cannot change in the current campaign state.');
        }
        if ($prior) {
            $id = $prior->id;
            if (isset($input['variants'])) {
                DB::table('preorder_campaign_variants')->where('campaign_id', $id)->orderBy('id')->lockForUpdate()->pluck('id');
            }
            $campaigns->where('id', $id)->update($fields + ['updated_at' => now()]);
        } else {
            $number = sprintf('PO-%s-%06d', now()->format('Ymd'), DB::scalar("SELECT nextval('preorder_number_seq')"));
            $id = $campaigns->insertGetId($fields + ['number' => $number]);
            app(Journal::class)->transition(Subject::Campaign, $id, 'status', null, 'DRAFT', 'create', $actor);
        }
        foreach ($input['variants'] ?? [] as $row) {
            $this->offer($id, $row, $actor);
        }
        $invalid = DB::table('preorder_campaign_variants')->where('campaign_id', $id);
        if ($next['payment_scheme'] === 'FULL_ONLY' ? $invalid->whereNotNull('deposit_unit_amount')->exists() : $invalid->whereNull('deposit_unit_amount')->exists()) {
            throw ValidationException::withMessages(['variants' => 'Each deposit must match the campaign payment scheme.']);
        }
        $eta = Arr::only($next, self::ETA);
        $event = $prior ? 'PreorderUpdated' : 'PreorderCreated';
        app(Journal::class)->audit($event, Subject::Campaign, $id, $actor, $prior ? (array) $prior : null, $next);
        app(Outbox::class)->record($event, 'preorder_campaign', $id, ['number' => $number]);
        if ($prior && array_diff_assoc($eta, (array) $prior)) {
            app(Outbox::class)->record('PreorderEtaUpdated', 'preorder_campaign', $id, ['number' => $number] + $eta);
        }

        return $number;
    }

    /** @param array<string,mixed> $row */
    private function offer(int $campaign, array $row, int $actor): void
    {
        if ($row['max_qty'] < $row['min_qty'] || (isset($row['deposit_unit_amount']) && $row['deposit_unit_amount'] >= $row['unit_amount'])) {
            throw ValidationException::withMessages(['variants' => 'Invalid purchase limits or deposit.']);
        }
        $variant = ProductDirectory::variants()->where('sku', $row['sku'])->value('id') ?? abort(404);
        $key = ['campaign_id' => $campaign, 'variant_id' => $variant];
        $offers = DB::table('preorder_campaign_variants');
        $prior = (clone $offers)->where($key)->lockForUpdate()->first();
        if ($prior && $prior->reserved_qty + $prior->confirmed_qty > $row['quota']) {
            throw new ApiProblem(409, 'QUOTA_FULL', 'Quota is below allocated units.');
        }
        $values = Arr::except($row, ['sku']) + ['deposit_unit_amount' => null];
        if ($prior) {
            $id = $prior->id;
            $offers->where('id', $id)->update($values);
        } else {
            $id = $offers->insertGetId($key + $values);
        }
        app(Journal::class)->audit('PreorderOfferUpdated', Subject::CampaignVariant, $id, $actor, $prior ? (array) $prior : null, $values);
    }
}
