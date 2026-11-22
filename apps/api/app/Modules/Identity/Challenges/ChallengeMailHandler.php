<?php

namespace App\Modules\Identity\Challenges;

use App\Modules\Foundation\Outbox\EventHandler;
use App\Modules\Foundation\Outbox\RecordedEvent;

/**
 * Queues SMTP delivery after the consumption commits; the handler itself has no side effects.
 */
final class ChallengeMailHandler implements EventHandler
{
    public function handle(RecordedEvent $event): void
    {
        SendChallengeMail::dispatch($event->type, $event->aggregateId)->afterCommit();
    }
}
