<?php

namespace App\Modules\RequestOrders;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QuotationDrafts
{
    private const FIELDS = ['valid_until', 'term', 'terms', 'deposit_amount', 'shipping_estimate_amount'];

    private const LIMIT = 1000000000000;

    /** @param array<string,mixed> $data */
    public static function save(object $case, ?object $draft, int $actor, array $data): object
    {
        if (! in_array($case->status, ['UNDER_REVIEW', 'QUOTED', 'REVISION_REQUESTED'], true)
            || ($draft ? $draft->status !== 'DRAFT' : DB::table('quotations')->where(['request_order_id' => $case->id, 'status' => 'DRAFT'])->exists())) {
            throw RequestCases::invalid();
        }
        $header = Arr::only($data + (array) $draft, self::FIELDS);
        QuotationLifecycle::requireFuture($header['valid_until']);
        $lines = isset($data['items']) ? self::lines($case->id, $data['items']) : null;
        $subtotal = $lines === null ? $draft->subtotal_amount : array_sum(array_map(fn ($i) => $i['qty'] * $i['unit_amount'], $lines));
        if ($subtotal < 1 || $subtotal > self::LIMIT) {
            throw ValidationException::withMessages(['items' => 'Total must be positive and within the IDR limit.']);
        }
        if ($header['term'] === 'FULL' && ! isset($data['deposit_amount'])) {
            $header['deposit_amount'] = null;
        }
        $deposit = $header['deposit_amount'] ?? null;
        if ($header['term'] === 'DEPOSIT' ? ! ($deposit > 0 && $deposit < $subtotal) : $deposit !== null) {
            throw ValidationException::withMessages(['deposit_amount' => 'A deposit must be positive and below the goods total; omit it for full payment.']);
        }
        $header += ['subtotal_amount' => $subtotal, 'total_amount' => $subtotal];
        $quotes = DB::table('quotations')->where('request_order_id', $case->id);
        if (! $draft) {
            $number = sprintf('QUO-%s-%06d', now()->format('Ymd'), DB::scalar("SELECT nextval('quotation_number_seq')"));
            $id = $quotes->insertGetId($header + ['number' => $number, 'version' => 1 + (int) $quotes->max('version'), 'request_order_id' => $case->id]);
        } else {
            $id = $draft->id;
            $quotes->where('id', $id)->update($header + ['revision' => $draft->revision + 1]);
        }
        if ($lines !== null) {
            DB::table('quotation_items')->where('quotation_id', $id)->delete();
            foreach ($lines as $line) {
                DB::table('quotation_items')->insert($line + ['quotation_id' => $id, 'request_order_id' => $case->id]);
            }
        }
        QuotationLifecycle::record($id, $case->id, $actor, $draft ? 'QuotationUpdated' : 'QuotationDrafted', $draft?->status, 'DRAFT');

        return QuotationData::one($id);
    }

    /** @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>> */
    private static function lines(int $case, array $items): array
    {
        $ids = DB::table('request_order_items')->where('request_order_id', $case)->pluck('id', 'public_id');
        foreach ($items as &$item) {
            $item['request_order_item_id'] = $ids[$item['request_item_id']] ?? throw ValidationException::withMessages(['items' => 'Select items belonging to this request.']);
            unset($item['request_item_id']);
            foreach ($item as $v) {
                if (is_string($v) && str_contains($v, "\0")) {
                    throw ValidationException::withMessages(['items' => 'Invalid text.']);
                }
            }
            if ($item['qty'] * $item['unit_amount'] > self::LIMIT || (isset($item['exchange_rate']) && ! preg_match('/[1-9]/', $item['exchange_rate']))) {
                throw ValidationException::withMessages(['items' => 'Invalid line amount or exchange rate.']);
            }
        }

        return $items;
    }
}
