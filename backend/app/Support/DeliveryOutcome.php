<?php

namespace App\Support;

use App\Models\WebhookDelivery;

final class DeliveryOutcome
{
    public function __construct(
        public readonly WebhookDelivery $delivery,
        /** Worth trying again: the failure may be temporary. */
        public readonly bool $retry,
    ) {}
}
