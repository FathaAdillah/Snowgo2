<?php

namespace App\Modules\Returns;

use App\Modules\Administration\OperationalSettings;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReturnClaims
{
    public static function invalid(): ApiProblem
    {
        return new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Return case cannot make this transition.');
    }

    public static function reason(string $code): int
    {
        return DB::table('reason_codes')->where(['domain' => 'returns', 'code' => $code, 'active' => true])->sharedLock()->value('id')
            ?? throw ValidationException::withMessages(['reason_code' => 'Select an active return reason.']);
    }

    /** @param array<string,mixed> $d */
    public static function open(int $actor, array $d, ?int $owner): string
    {
        $o = DB::table('orders')->where('number', $d['order_number'])->when($owner !== null, fn ($q) => $q->where('user_id', $owner))->lockForUpdate()->first() ?? abort(404);
        $s = DB::table('shipments')->where(['order_id' => $o->id, 'number' => $d['shipment_number']])->lockForUpdate()->first() ?? abort(404);
        if ($s->status !== 'DELIVERED' || ! $s->delivered_at || now()->gt(Carbon::parse($s->delivered_at)->addHours(OperationalSettings::value('returns.window_hours')))) {
            throw new ApiProblem(409, 'RETURN_WINDOW_CLOSED', 'Shipment is outside its return window.');
        }
        $number = 'RTN-'.Str::ulid();
        $id = DB::table('returns')->insertGetId(['number' => $number, 'order_id' => $o->id, 'shipment_id' => $s->id, 'requested_by' => $actor, 'reason_id' => self::reason($d['reason_code']),
            'status' => 'REQUESTED', 'note' => $d['note'] ?? null]);
        foreach ($d['items'] as $line) {
            $item = DB::table('shipment_items')->where(['public_id' => $line['shipment_item_id'], 'shipment_id' => $s->id])->first() ?? abort(404);
            $coverage = DB::scalar(<<<'SQL'
                WITH available AS (
                    SELECT coalesce(s.replacement_coverage, int8multirange(int8range(s.coverage_start::bigint,s.coverage_start::bigint+s.qty)))
                        - coalesce((SELECT range_agg(l.unit_coverage) FROM return_items l JOIN returns r ON r.id=l.return_id
                            WHERE l.shipment_item_id=s.id AND r.status NOT IN ('CANCELLED','REJECTED')
                              AND r.resolution IS DISTINCT FROM 'REJECTED'), '{}'::int8multirange) AS units
                    FROM shipment_items s WHERE s.id=?
                )
                SELECT return_first_units(units,?) FROM available
                WHERE (SELECT sum(upper(r)-lower(r)) FROM unnest(units) r)>=?
                SQL, [$item->id, $line['qty'], $line['qty']]);
            if ($coverage === null) {
                throw new ApiProblem(409, 'QUANTITY_CONFLICT', 'Return quantity exceeds delivered units available for claim.');
            }
            DB::table('return_items')->insert(['return_id' => $id, 'shipment_item_id' => $item->id, 'qty' => $line['qty'], 'unit_coverage' => $coverage]);
        }
        DB::table('order_transition_holds')->insert(['order_id' => $o->id, 'kind' => 'DISPUTE', 'source_ref' => $number]);
        self::record($id, null, 'REQUESTED', $actor, 'ReturnRequested');

        return $number;
    }

    public static function lock(string $number, ?int $owner = null): object
    {
        $id = DB::table('returns')->where('number', $number)->value('order_id') ?? abort(404);
        DB::table('orders')->where('id', $id)->when($owner !== null, fn ($q) => $q->where('user_id', $owner))->lockForUpdate()->first() ?? abort(404);

        return DB::table('returns')->where('number', $number)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string,mixed> $d */
    public static function review(object $r, int $actor, array $d): void
    {
        [$from,$to] = match ($d['decision']) {
            'START' => ['REQUESTED', 'UNDER_REVIEW'],
            'APPROVE' => ['UNDER_REVIEW', 'APPROVED'],
            'REJECT' => ['UNDER_REVIEW', 'REJECTED'],
            'GIVE_INSTRUCTIONS' => ['APPROVED', 'AWAITING_SHIPMENT'],
            'WAIVE_SHIPMENT' => ['APPROVED', 'INSPECTING'],
            default => throw self::invalid(),
        };
        if ($r->status !== $from) {
            throw self::invalid();
        }
        if ($to === 'APPROVED' && DB::table('stored_files')->where(['owner_type' => 'return', 'owner_id' => $r->id, 'collection' => 'return_evidence', 'status' => 'READY', 'visibility' => 'PRIVATE'])
            ->where('mime', 'like', 'image/%')->distinct()->count('sha256') < 2) {
            throw new ApiProblem(409, 'FILE_NOT_READY', 'Two ready photos of the item and label are required.');
        }
        $extra = isset($d['reason_code']) ? ['review_reason_id' => self::reason($d['reason_code'])] : [];
        if ($to === 'REJECTED') {
            $extra += ['resolution' => 'REJECTED', 'resolved_at' => now()];
        }
        self::change($r, $to, $actor, 'ReturnReviewed', $extra + (isset($d['instructions']) ? ['instructions' => $d['instructions']] : []));
    }

    /** @param array<string,mixed> $columns */
    public static function change(object $r, string $to, int $actor, string $event, array $columns = []): void
    {
        DB::table('returns')->where('id', $r->id)->update(['status' => $to] + $columns);
        if (in_array($to, ['REJECTED', 'RESOLVED', 'CANCELLED'], true)) {
            DB::table('order_transition_holds')->where(['order_id' => $r->order_id, 'kind' => 'DISPUTE', 'source_ref' => $r->number])->delete();
        }
        self::record($r->id, $r->status, $to, $actor, $event);
    }

    private static function record(int $id, ?string $from, string $to, int $actor, string $event): void
    {
        app(Journal::class)->transition(Subject::ReturnCase, $id, 'status', $from, $to, (string) Str::ulid(), $actor);
        app(Journal::class)->audit($event, Subject::ReturnCase, $id, $actor, $from ? ['status' => $from] : null, ['status' => $to]);
        app(Outbox::class)->record($event, 'return', $id, ['status' => $to]);
    }
}
