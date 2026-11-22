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
            'before' => $this->snapshot($before), 'after' => $this->snapshot($after),
            'ip' => request()->ip(), 'user_agent' => mb_substr(request()->userAgent() ?? '', 0, 255),
            'request_id' => Context::get('request_id') ?? RequestId::current(),
        ]);
    }

    public function transition(Subject $subject, int $id, string $dimension, ?string $from, string $to, string $eventKey, ?int $actorId = null, ?int $reasonId = null): void
    {
        $this->requireTransaction();
        DB::table('status_histories')->insert([
            'subject_type' => $subject->value, 'subject_id' => $id, 'dimension' => $dimension,
            'from_status' => $from, 'to_status' => $to, 'event_key' => $eventKey,
            'actor_id' => $actorId, 'reason_id' => $reasonId,
        ]);
    }

    /** @param array<string,mixed>|null $data */
    private function snapshot(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        $safe = array_intersect_key($data, array_flip(['status', 'active', 'amount_idr', 'quantity', 'role', 'visibility']));

        return json_encode((object) array_filter($safe, is_scalar(...)), JSON_THROW_ON_ERROR);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Journal writes must be part of the domain transaction.');
        }
    }
}
