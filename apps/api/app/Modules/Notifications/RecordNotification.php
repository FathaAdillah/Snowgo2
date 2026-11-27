<?php

namespace App\Modules\Notifications;

use App\Models\User;
use App\Modules\Foundation\Outbox\EventHandler;
use App\Modules\Foundation\Outbox\RecordedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordNotification implements EventHandler
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    public function handle(RecordedEvent $event): void
    {
        if (in_array($event->type, NotificationTemplates::CHALLENGES, true)) {
            $this->delivery($event, $event->aggregateId);

            return;
        }

        foreach ($this->recipients->customers($event) as $recipient) {
            $message = NotificationTemplates::message($event->type, $recipient->number, $this->recipients->link($event->aggregate, $recipient->path_ref));
            DB::table('notifications')->insertOrIgnore([
                'public_id' => (string) Str::ulid(), 'event_id' => $event->id, 'user_id' => $recipient->user_id,
                'template' => $event->type, 'payload' => json_encode($message, JSON_THROW_ON_ERROR),
            ]);
            $inbox = DB::table('notifications')->where('event_id', $event->id)->where('user_id', $recipient->user_id)
                ->where('template', $event->type)->value('id');
            $this->delivery($event, $recipient->user_id, $inbox);
        }

        if (in_array($event->type, ['RequestOrderSubmitted', 'QuotationResponded'], true)) {
            User::query()->where('account_type', User::STAFF)->whereNull('disabled_at')->whereNotNull('email_verified_at')
                ->eachById(function (User $staff) use ($event) {
                    if ($staff->can('request_orders.request.view')) {
                        $this->delivery($event, $staff->id);
                    }
                });
        }
    }

    private function delivery(RecordedEvent $event, int $userId, ?int $notificationId = null): void
    {
        $key = ['event_id' => $event->id, 'user_id' => $userId, 'channel' => 'EMAIL', 'template' => $event->type];
        $inserted = DB::table('notification_deliveries')->insertOrIgnore($key + ['notification_id' => $notificationId]);
        if ($inserted > 0) {
            $id = DB::table('notification_deliveries')->where($key)->value('id');
            DB::afterCommit(static fn () => DispatchNotifications::enqueue($id));
        }
    }
}
