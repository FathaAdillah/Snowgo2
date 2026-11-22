<?php

namespace App\Modules\Identity\Challenges;

use App\Modules\Foundation\Outbox\EventHandler;
use App\Modules\Foundation\Outbox\RecordedEvent;

/**
 * Queues SMTP delivery; the job tolerates running before the receipt commits because the challenge is already committed.
 */
final class ChallengeMailHandler implements EventHandler
{
    public function handle(RecordedEvent $event): void
    {
        // Enqueue inside the receipt transaction: an enqueue failure rolls the receipt back so the relay retries.
        SendChallengeMail::dispatch($event->type, $event->aggregateId);
    }
}
