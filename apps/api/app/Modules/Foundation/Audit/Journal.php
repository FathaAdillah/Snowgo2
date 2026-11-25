<?php

namespace App\Modules\Foundation\Audit;

use App\Support\Http\RequestId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;

final class Journal
{
    /**
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     */
    public function audit(string $action, Subject $subject, ?int $id, ?int $actorId, ?array $before = null, ?array $after = null): void
    {
        $this->requireTransaction();
        DB::table('audit_logs')->insert([
            'actor_id' => $actorId, 'action' => $action, 'subject_type' => $subject->value, 'subject_id' => $id,
            'before' => $this->snapshot($before, $subject), 'after' => $this->snapshot($after, $subject),
            'ip' => request()->ip(), 'user_agent' => mb_substr(request()->userAgent() ?? '', 0, 255),
            'request_id' => Context::get('request_id') ?? RequestId::current(),
        ]);
    }

    public function transition(Subject $subject, int $id, string $dimension, ?string $from, string $to, string $eventKey, ?int $actorId = null, ?int $reasonId = null): int
    {
        $this->requireTransaction();

        return DB::table('status_histories')->insertGetId([
            'subject_type' => $subject->value, 'subject_id' => $id, 'dimension' => $dimension,
            'from_status' => $from, 'to_status' => $to, 'event_key' => $eventKey,
            'actor_id' => $actorId, 'reason_id' => $reasonId,
        ]);
    }

    /** @param array<string,mixed>|null $data */
    private function snapshot(?array $data, Subject $subject): ?string
    {
        if ($data === null) {
            return null;
        }
        $fields = ['status', 'active', 'amount_idr', 'quantity', 'role', 'grants', 'visibility'];
        if (in_array($subject, [Subject::Courier, Subject::CourierService, Subject::ShippingRate], true)) {
            $fields = [...$fields, 'code', 'name', 'courier_id', 'service_id', 'origin_region_id', 'destination_region_id', 'eta_min_days', 'eta_max_days', 'base_amount', 'amount_per_kg'];
        }
        if ($subject === Subject::Setting) {
            $fields = [...$fields, 'key', 'value'];
        }
        if ($subject === Subject::BankAccount) {
            $fields = [...$fields, 'bank_code', 'masked_number'];
        }
        if ($subject === Subject::RequestOrder) {
            $fields = [...$fields, 'assigned_to', 'message_id'];
        }
        if ($subject === Subject::Batch) {
            $fields = [...$fields, 'stage', 'eta_date', 'received_qty', 'failed_qty', 'order_item_id'];
        }
        if ($subject === Subject::Campaign) {
            $fields = [...$fields, 'slug', 'name', 'starts_at', 'ends_at', 'ready_eta_from', 'ready_eta_to', 'ship_eta_from', 'ship_eta_to', 'payment_scheme'];
        }
        if ($subject === Subject::CampaignVariant) {
            $fields = [...$fields, 'quota', 'unit_amount', 'deposit_unit_amount', 'min_qty', 'max_qty'];
        }
        if ($subject === Subject::Cart) {
            $fields[] = 'version';
        }
        if ($subject === Subject::StockReservation) {
            $fields[] = 'expires_at';
        }
        if ($subject === Subject::Warehouse) {
            $fields = [...$fields, 'code', 'name', 'district_id', 'is_default'];
        }
        if ($subject === Subject::Variant) {
            $fields = [...$fields, 'rts_enabled', 'rts_price_amount'];
        }
        if (in_array($subject, [Subject::Region, Subject::Reason, Subject::Unit, Subject::TaxClass], true)) {
            $fields = [...$fields, 'code', 'name', 'domain', 'label', 'rate_bps', 'parent_id', 'kind'];
        }
        $safe = array_intersect_key($data, array_flip($fields));

        return json_encode((object) array_filter($safe, fn ($v, $k) => is_scalar($v) || ($k === 'rts_price_amount' && $v === null), ARRAY_FILTER_USE_BOTH), JSON_THROW_ON_ERROR);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Journal writes must be part of the domain transaction.');
        }
    }
}
