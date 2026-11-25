<?php

namespace App\Modules\Preorders;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Orders\OrderLifecycle;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CampaignWindow
{
    public const ACTIONS = ['SCHEDULE', 'OPEN', 'CLOSE', 'START_PROCUREMENT', 'FULFILL', 'COMPLETE', 'CANCEL'];

    /** @param array<string,string> $input */
    public function act(string $number, string $action, int $actor, array $input): void
    {
        $c = DB::table('preorder_campaigns')->where('number', $number)->lockForUpdate()->first() ?? abort(404);
        $now = DB::scalar('SELECT clock_timestamp()');
        $started = DB::scalar('SELECT ?::timestamptz >= ?::timestamptz', [$now, $c->starts_at]);
        $ended = DB::scalar('SELECT ?::timestamptz >= ?::timestamptz', [$now, $c->ends_at]);
        $allocations = DB::table('preorder_allocations as a')->join('preorder_campaign_variants as v', 'v.id', '=', 'a.campaign_variant_id')
            ->where('v.campaign_id', $c->id);
        $target = match (true) {
            $action === 'SCHEDULE' && $c->status === 'DRAFT' && ! $ended => 'SCHEDULED',
            $action === 'OPEN' && $c->status === 'SCHEDULED' && $started && ! $ended => 'OPEN',
            $action === 'CLOSE' && $c->status === 'OPEN' => 'CLOSED',
            $action === 'START_PROCUREMENT' && $c->status === 'CLOSED' && ! (clone $allocations)->where('a.status', 'RESERVED')->exists()
                && (clone $allocations)->where('a.status', 'CONFIRMED')->exists() => 'IN_PROCUREMENT',
            $action === 'FULFILL' && $c->status === 'IN_PROCUREMENT' && $c->first_received_at !== null => 'FULFILLING',
            $action === 'COMPLETE' && $c->status === 'FULFILLING' && ! (clone $allocations)->whereIn('a.status', ['RESERVED', 'CONFIRMED'])->exists() => 'COMPLETED',
            $action === 'CANCEL' && ! in_array($c->status, ['COMPLETED', 'CANCELLED'], true) => 'CANCELLED',
            default => throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Campaign cannot make this transition.'),
        };
        $reason = isset($input['reason_code']) ? DB::table('reason_codes')->where(['code' => $input['reason_code'], 'domain' => 'orders', 'active' => true])
            ->sharedLock()->value('id') ?? throw ValidationException::withMessages(['reason_code' => 'Select an active order reason.']) : null;
        if ($target === 'CANCELLED') {
            // Claim all parents before releasing shared SKU quota; expiry locks parent then quota.
            $orders = DB::table('orders')->where('campaign_id', $c->id)->whereNotIn('status', ['EXPIRED', 'CANCELLED'])->orderBy('id')->lockForUpdate()->pluck('number');
            foreach ($orders as $order) {
                app(OrderLifecycle::class)->apply($order, null, $actor, 'CANCEL', $input);
            }
        }
        $this->transition($c, $target, $actor, $reason, $input['note'] ?? null);
    }

    public function advanceDue(): int
    {
        $count = 0;
        $due = DB::table('preorder_campaigns')->whereRaw("((status='SCHEDULED' AND starts_at<=clock_timestamp()) OR (status='OPEN' AND ends_at<=clock_timestamp()))");
        foreach ((clone $due)->select('id')->lazyById(100) as $candidate) {
            $count += DB::transaction(function () use ($due, $candidate) {
                $c = (clone $due)->where('id', $candidate->id)->lock('FOR UPDATE SKIP LOCKED')->first();
                if (! $c) {
                    return 0;
                }
                if ($c->status === 'SCHEDULED') {
                    $this->transition($c, 'OPEN', null);
                    $c->status = 'OPEN';
                }
                if (DB::scalar('SELECT ?::timestamptz <= clock_timestamp()', [$c->ends_at])) {
                    $this->transition($c, 'CLOSED', null);
                }

                return 1;
            });
        }

        return $count;
    }

    /** Procurement calls this inside the transaction recording its first positive receipt. */
    public function recordReceipt(int $campaign): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Record the receipt and its campaign milestone together.');
        }
        $row = DB::table('preorder_campaigns')->where('id', $campaign)->lockForUpdate()->first() ?? abort(404);
        if ($row->first_received_at !== null) {
            return;
        }
        if ($row->status !== 'IN_PROCUREMENT') {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Campaign is not in procurement.');
        }
        DB::table('preorder_campaigns')->where('id', $campaign)->update(['first_received_at' => DB::raw('clock_timestamp()')]);
    }

    private function transition(object $c, string $target, ?int $actor, ?int $reason = null, ?string $note = null): void
    {
        DB::table('preorder_campaigns')->where('id', $c->id)->update(['status' => $target, 'updated_at' => now()]);
        $journal = app(Journal::class);
        $history = $journal->transition(Subject::Campaign, $c->id, 'status', $c->status, $target, 'campaign:'.$target, $actor, $reason);
        if ($note !== null) {
            DB::table('preorder_transition_notes')->insert(['history_id' => $history, 'note' => $note]);
        }
        $journal->audit('PreorderStatusChanged', Subject::Campaign, $c->id, $actor, ['status' => $c->status], ['status' => $target]);
        app(Outbox::class)->record('PreorderStatusChanged', 'preorder_campaign', $c->id, ['number' => $c->number, 'status' => $target]);
    }
}
