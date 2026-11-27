<?php

namespace App\Modules\Notifications;

use App\Models\User;
use App\Modules\Identity\Challenges\SendChallengeMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use stdClass;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Email;
use Throwable;

final class SendNotificationEmail implements ShouldQueue
{
    use Queueable;

    private const BACKOFF = [10, 60, 300, 900];

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $delivery = $this->claim();
        if ($delivery === null) {
            return;
        }

        $user = User::query()->whereKey($delivery->user_id)->whereNull('disabled_at')->first();
        if ($user === null) {
            $this->finish('FAILED');

            return;
        }
        $challenge = null;
        $ciphertext = null;
        try {
            if (in_array($delivery->template, NotificationTemplates::CHALLENGES, true)) {
                $challenge = new SendChallengeMail($delivery->template, $user->id);
                $prepared = $challenge->prepare($user, $delivery->event_id);
            } else {
                $prepared = $this->lifecycle($delivery, $user);
            }
            if ($prepared === null) {
                $this->finish('FAILED');

                return;
            }

            [$address, $mail] = $prepared;
            $ciphertext = $prepared[2] ?? null;
            $mail->withSymfonyMessage(fn (Email $email) => $email->getHeaders()->addIdHeader('Message-ID', $delivery->provider_ref));
        } catch (Throwable) {
            $this->finish('FAILED');

            return;
        }

        $status = 'SENT';
        $delay = null;
        try {
            Mail::to($address)->send($mail);
        } catch (UnexpectedResponseException $exception) {
            $code = $exception->getCode();
            $delay = $code >= 400 && $code < 500 ? (self::BACKOFF[$delivery->attempts - 1] ?? null) : null;
            $status = $delay !== null ? 'PENDING' : ($code >= 400 && $code < 600 ? 'FAILED' : 'AMBIGUOUS');
        } catch (Throwable) {
            $status = 'AMBIGUOUS';
        }
        DB::transaction(function () use ($status, $delay, $challenge, $user, $ciphertext) {
            if ($this->finish($status, $delay) && $challenge !== null && $ciphertext !== null && in_array($status, ['SENT', 'FAILED'], true)) {
                $challenge->forget($user, $ciphertext);
            }
        });
    }

    private function claim(): ?stdClass
    {
        return DB::transaction(function () {
            $row = DB::table('notification_deliveries')->where('id', $this->deliveryId);
            $delivery = (clone $row)->where('status', 'PENDING')->where('next_attempt_at', '<=', now())->lockForUpdate()->first();
            if ($delivery === null) {
                return null;
            }

            $uuid = DB::table('outbox_events')->where('id', $delivery->event_id)->value('event_uuid');
            $delivery->provider_ref = $this->deliveryId.'.'.$uuid.'@snowego-notifications.invalid';
            $delivery->attempts++;
            $row->update([
                'status' => 'SENDING', 'lease_until' => now()->addMinutes(2), 'next_attempt_at' => null,
                'attempts' => $delivery->attempts, 'provider_ref' => $delivery->provider_ref, 'updated_at' => now(),
            ]);

            return $delivery;
        });
    }

    /** @return array{string, Mailable}|null */
    private function lifecycle(stdClass $delivery, User $user): ?array
    {
        if ($delivery->notification_id === null) {
            if (! $user->can('request_orders.request.view')) {
                return null;
            }
            $notice = NotificationTemplates::message($delivery->template, '', '');
        } else {
            $notice = json_decode(DB::table('notifications')->where('id', $delivery->notification_id)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        }

        return [$user->email, new LifecycleMail($notice)];
    }

    private function finish(string $status, ?int $delay = null): bool
    {
        return DB::table('notification_deliveries')->where('id', $this->deliveryId)->where('status', 'SENDING')->update([
            'status' => $status, 'lease_until' => null, 'next_attempt_at' => $delay === null ? null : now()->addSeconds($delay),
            'updated_at' => now(),
        ]) === 1;
    }
}
