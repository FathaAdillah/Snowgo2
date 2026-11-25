<?php

namespace App\Modules\RequestOrders;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class QuotationLifecycle
{
    public static function requireFuture(string $until): void
    {
        if (! DB::scalar('SELECT ?::timestamptz > clock_timestamp()', [$until])) {
            throw new ApiProblem(409, 'QUOTE_EXPIRED', 'The quotation has expired.');
        }
    }

    public static function dispatch(object $case, object $quote, int $actor): object
    {
        if ($quote->status !== 'DRAFT' || ! in_array($case->status, ['UNDER_REVIEW', 'REVISION_REQUESTED', 'QUOTED'], true)) {
            throw RequestCases::invalid();
        }
        self::requireFuture($quote->valid_until);
        $prior = DB::table('quotations')->where(['request_order_id' => $case->id, 'status' => 'SENT'])->lockForUpdate()->first();
        if ($prior) {
            $expired = DB::scalar('SELECT ?::timestamptz <= clock_timestamp()', [$prior->valid_until]);
            self::change($prior, $actor, $expired ? 'EXPIRED' : 'SUPERSEDED', $expired ? 'QuotationExpired' : 'QuotationSuperseded');
        }
        self::change($quote, $actor, 'SENT', 'QuotationSent', ['sent_at' => DB::raw('clock_timestamp()')]);
        if ($case->status !== 'QUOTED') {
            RequestCases::transition($case, $actor, 'QUOTED', 'RequestOrderQuoted');
        }

        return QuotationData::one($quote->id);
    }

    public static function withdraw(object $case, object $quote, int $actor, string $reason): object
    {
        if (! in_array($quote->status, ['DRAFT', 'SENT'], true)) {
            throw RequestCases::invalid();
        }
        if ($quote->status === 'SENT') {
            self::requireFuture($quote->valid_until);
        }
        $id = RequestCases::reason($reason);
        self::change($quote, $actor, 'WITHDRAWN', 'QuotationWithdrawn', reason: $id);
        if ($quote->status === 'SENT') {
            RequestCases::transition($case, $actor, 'UNDER_REVIEW', 'RequestOrderReviewed', $id);
        }

        return QuotationData::one($quote->id);
    }

    public static function closeOpen(int $request, int $actor, ?int $reason = null): void
    {
        foreach (DB::table('quotations')->where('request_order_id', $request)->whereIn('status', ['DRAFT', 'SENT'])->orderBy('id')->lockForUpdate()->get() as $q) {
            self::change($q, $actor, 'WITHDRAWN', 'QuotationWithdrawn', reason: $reason);
        }
    }

    /** @param array<string,string> $data
     * @return array<string,mixed> */
    public static function decide(object $case, object $q, int $actor, array $data): array
    {
        abort_unless($q->sent_at !== null, 404);
        $decision = $data['decision'];
        $intent = hash('sha256', json_encode([$data['address_id'] ?? null, $data['note'] ?? null], JSON_THROW_ON_ERROR));
        if ($decision === 'ACCEPT' && $q->status === 'ACCEPTED' && hash_equals($q->acceptance_hash, $intent)) {
            return ['quotation' => QuotationData::one($q->id, false), 'request_status' => 'CONVERTED_TO_ORDER'] + QuotationConversion::result($case->id);
        }
        if ($q->status === 'EXPIRED') {
            throw new ApiProblem(409, 'QUOTE_EXPIRED', 'The quotation has expired.');
        }
        if ($case->status !== 'QUOTED' || $q->status !== 'SENT') {
            throw RequestCases::invalid();
        }
        self::requireFuture($q->valid_until);
        if (isset($data['note'])) {
            RequestCases::message($case, $actor, 'CUSTOMER', $data['note']);
        }
        $result = [];
        if ($decision === 'ACCEPT') {
            $result = QuotationConversion::create($case, $q, $data['address_id']);
            self::requireFuture($q->valid_until);
            self::change($q, $actor, 'ACCEPTED', 'QuotationAccepted', ['acceptance_hash' => $intent, 'accepted_at' => DB::raw('clock_timestamp()')]);
            self::closeOpen($case->id, $actor);
            $status = 'CONVERTED_TO_ORDER';
        } else {
            $status = $decision === 'REVISE' ? 'REVISION_REQUESTED' : 'UNDER_REVIEW';
            self::change($q, $actor, $decision === 'REVISE' ? 'SUPERSEDED' : 'REJECTED', 'QuotationResponded');
        }
        RequestCases::transition($case, $actor, $status, $decision === 'ACCEPT' ? 'RequestOrderConverted' : 'RequestOrderReviewed');

        return ['quotation' => QuotationData::one($q->id, false), 'request_status' => $status] + $result;
    }

    public static function expireDue(): int
    {
        $ids = DB::table('quotations')->where('status', 'SENT')->whereRaw('valid_until <= clock_timestamp()')->orderBy('valid_until')->limit(200)->pluck('request_order_id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $case = DB::table('request_orders')->where('id', $id)->lock('FOR UPDATE SKIP LOCKED')->first();
                if (! $case || $case->status !== 'QUOTED') {
                    return 0;
                }
                $q = DB::table('quotations')->where(['request_order_id' => $id, 'status' => 'SENT'])->whereRaw('valid_until <= clock_timestamp()')->lockForUpdate()->first();
                if (! $q) {
                    return 0;
                }
                self::change($q, null, 'EXPIRED', 'QuotationExpired');
                RequestCases::transition($case, null, 'EXPIRED', 'RequestOrderExpired');

                return 1;
            });
        }

        return $count;
    }

    /** @param array<string,mixed> $extra */
    private static function change(object $q, ?int $actor, string $to, string $event, array $extra = [], ?int $reason = null): void
    {
        DB::table('quotations')->where('id', $q->id)->update(['status' => $to] + $extra);
        self::record($q->id, $q->request_order_id, $actor, $event, $q->status, $to, $reason);
    }

    public static function record(int $id, int $request, ?int $actor, string $event, ?string $from, string $to, ?int $reason = null): void
    {
        $journal = app(Journal::class);
        if ($from !== $to) {
            $journal->transition(Subject::Quotation, $id, 'status', $from, $to, (string) Str::ulid(), $actor, $reason);
        }
        $journal->audit($event, Subject::Quotation, $id, $actor, $from === null ? null : ['status' => $from], ['status' => $to]);
        app(Outbox::class)->record($event, 'quotation', $id, ['status' => $to, 'request_order_id' => $request]);
    }
}
