<?php

use App\Modules\Identity\Challenges\ChallengeMailHandler;

return [
    // event type => [stable handler name => EventHandler implementation]
    'handlers' => [
        'EmailVerificationRequested' => ['identity.challenge-mail' => ChallengeMailHandler::class],
        'PasswordResetRequested' => ['identity.challenge-mail' => ChallengeMailHandler::class],
    ],
];
