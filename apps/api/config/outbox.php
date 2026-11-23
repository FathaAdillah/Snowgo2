<?php

use App\Modules\Catalog\PublishMedia;
use App\Modules\Identity\Challenges\ChallengeMailHandler;
use App\Modules\Identity\Google\LinkedNotice;

return [
    // event type => [stable handler name => EventHandler implementation]
    'handlers' => [
        'ProductChanged' => ['catalog.publish-media' => PublishMedia::class],
        'VariantChanged' => ['catalog.publish-media' => PublishMedia::class],
        'ProductMediaChanged' => ['catalog.publish-media' => PublishMedia::class],
        'StaffInvited' => ['identity.challenge-mail' => ChallengeMailHandler::class],
        'StaffEmailChangeRequested' => ['identity.challenge-mail' => ChallengeMailHandler::class],
        'GoogleAccountLinked' => ['identity.google-linked-mail' => LinkedNotice::class],
        'EmailVerificationRequested' => ['identity.challenge-mail' => ChallengeMailHandler::class],
        'PasswordResetRequested' => ['identity.challenge-mail' => ChallengeMailHandler::class],
    ],
];
