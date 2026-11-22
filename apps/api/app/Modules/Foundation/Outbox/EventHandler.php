<?php

namespace App\Modules\Foundation\Outbox;

interface EventHandler
{
    /** Apply database effects only, inside the consumption transaction. */
    public function handle(RecordedEvent $event): void;
}
