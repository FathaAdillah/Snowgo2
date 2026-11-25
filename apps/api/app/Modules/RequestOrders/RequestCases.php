<?php

namespace App\Modules\RequestOrders;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Identity\Staff\StaffAccess;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RequestCases
{
    public const DISCUSS = ['SUBMITTED', 'UNDER_REVIEW', 'NEED_INFORMATION', 'QUOTED', 'REVISION_REQUESTED'];

    public const REVIEWS = [
        'SUBMITTED' => ['START' => 'UNDER_REVIEW', 'REJECT' => 'REJECTED'],
        'UNDER_REVIEW' => ['NEED_INFORMATION' => 'NEED_INFORMATION', 'REJECT' => 'REJECTED'],
        'NEED_INFORMATION' => ['START' => 'UNDER_REVIEW'],
        'REVISION_REQUESTED' => ['START' => 'UNDER_REVIEW'],
        'EXPIRED' => ['REOPEN' => 'UNDER_REVIEW'],
    ];

    /** @param array<string,mixed> $data */
    public static function submit(int $owner, array $data): object
    {
        $number = 'REQ-'.Str::ulid();
        $id = DB::table('request_orders')->insertGetId(['user_id' => $owner, 'number' => $number] + Arr::only($data, ['title', 'description', 'budget_amount', 'target_date']));
        foreach ($data['items'] as $item) {
            DB::table('request_order_items')->insert(['request_order_id' => $id, 'public_id' => (string) Str::ulid()] + $item);
        }
        app(Journal::class)->transition(Subject::RequestOrder, $id, 'status', null, 'SUBMITTED', 'submitted', $owner);
        self::event('RequestOrderSubmitted', $id, $owner, ['status' => 'SUBMITTED']);

        return RequestSource::lock($id);
    }

    /** @param array<string,mixed> $data */
    public static function review(object $case, int $actor, array $data): void
    {
        $to = self::REVIEWS[$case->status][$data['decision']] ?? throw self::invalid();
        $assignee = $data['assignee_id'] ?? $case->assigned_to ?? ($data['decision'] === 'START' ? $actor : null);
        if (isset($data['assignee_id'])) {
            $target = User::query()->where('account_type', User::STAFF)->whereNull('disabled_at')
                ->whereNotNull('two_factor_confirmed_at')->sharedLock()->find($assignee);
            if (! $target || array_diff(['request_orders.request.view', 'request_orders.request.review'], StaffAccess::permissions($target))) {
                throw ValidationException::withMessages(['assignee_id' => 'Select an active staff member with request review access.']);
            }
        }
        $reason = self::reason($data['reason_code'] ?? null);
        DB::table('request_orders')->where('id', $case->id)->update(['assigned_to' => $assignee]);
        if (isset($data['note'])) {
            self::insertMessage($case, $actor, 'STAFF', $data['note']);
        }
        self::transition($case, $actor, $to, 'RequestOrderReviewed', $reason, ['assigned_to' => $assignee]);
    }

    public static function cancel(object $case, int $actor, string $reason): void
    {
        // QUOTED needs atomic withdrawal of its active quotation (TASK-020).
        if (! in_array($case->status, ['SUBMITTED', 'UNDER_REVIEW', 'NEED_INFORMATION', 'REVISION_REQUESTED', 'EXPIRED'], true)) {
            throw self::invalid();
        }
        self::transition($case, $actor, 'CANCELLED', 'RequestOrderCancelled', self::reason($reason));
    }

    public static function message(object $case, int $actor, string $visibility, string $body): int
    {
        if (! in_array($case->status, self::DISCUSS, true)) {
            throw self::invalid();
        }

        return self::insertMessage($case, $actor, $visibility, $body);
    }

    private static function insertMessage(object $case, int $actor, string $visibility, string $body): int
    {
        $id = DB::table('request_order_messages')->insertGetId(['request_order_id' => $case->id, 'author_id' => $actor, 'visibility' => $visibility, 'body' => $body]);
        DB::table('request_orders')->where('id', $case->id)->update(['updated_at' => now()]);
        self::event('RequestMessageAdded', $case->id, $actor, ['visibility' => $visibility, 'message_id' => $id]);

        return $id;
    }

    private static function reason(?string $code): ?int
    {
        return $code === null ? null : DB::table('reason_codes')->where(['domain' => 'request_orders', 'code' => $code, 'active' => true])->sharedLock()->value('id')
            ?? throw ValidationException::withMessages(['reason_code' => 'Select an active request order reason.']);
    }

    /** @param array<string,mixed> $meta */
    private static function transition(object $case, int $actor, string $to, string $event, ?int $reason, array $meta = []): void
    {
        DB::table('request_orders')->where('id', $case->id)->update(['status' => $to, 'updated_at' => now()]);
        app(Journal::class)->transition(Subject::RequestOrder, $case->id, 'status', $case->status, $to, (string) Str::ulid(), $actor, $reason);
        self::event($event, $case->id, $actor, ['status' => $to] + $meta);
    }

    /** @param array<string,mixed> $meta */
    private static function event(string $event, int $id, int $actor, array $meta): void
    {
        app(Journal::class)->audit($event, Subject::RequestOrder, $id, $actor, null, $meta);
        app(Outbox::class)->record($event, 'request_order', $id, $meta);
    }

    public static function invalid(): ApiProblem
    {
        return new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'This action is not available for this request.');
    }
}
