<?php

use App\Modules\Identity\Challenges\ChallengeMailHandler;
use App\Modules\Identity\Google\LinkedNotice;

return [
    // event type => [stable handler name => EventHandler implementation]
    'handlers' => [
        'StaffInvited' => ['identity.challenge-mail' => ChallengeMailHandler::class],
        'GoogleAccountLinked' => ['identity.google-linked-mail' => LinkedNotice::class],
        'EmailVerificationRequested' => ['identity.challenge-mail' => ChallengeMailHandler::class],
        'PasswordResetRequested' => ['identity.challenge-mail' => ChallengeMailHandler::class],
    ],
];
