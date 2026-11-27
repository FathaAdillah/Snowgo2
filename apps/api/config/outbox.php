<?php

use App\Modules\Catalog\PublishMedia;
use App\Modules\Invoicing\InvoiceDocuments;
use App\Modules\Notifications\NotificationTemplates;
use App\Modules\Notifications\RecordNotification;

return [
    // event type => [stable handler name => EventHandler implementation]
    'handlers' => [
        'InvoiceIssued' => ['invoicing.pdf' => InvoiceDocuments::class, 'invoicing.mail' => RecordNotification::class],
        'ProductChanged' => ['catalog.publish-media' => PublishMedia::class],
        'VariantChanged' => ['catalog.publish-media' => PublishMedia::class],
        'ProductMediaChanged' => ['catalog.publish-media' => PublishMedia::class],
        'GoogleAccountLinked' => ['identity.google-linked-mail' => RecordNotification::class],
        ...array_fill_keys(NotificationTemplates::CHALLENGES, ['identity.challenge-mail' => RecordNotification::class]),
    ] + array_fill_keys(NotificationTemplates::lifecycleEvents(), ['notifications.inbox' => RecordNotification::class]),
];
