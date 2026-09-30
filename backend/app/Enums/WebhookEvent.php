<?php

namespace App\Enums;

/** What a webhook can subscribe to. `ping` is only ever sent by the test button. */
enum WebhookEvent: string
{
    case LinkCreated = 'link.created';
    case LinkUpdated = 'link.updated';
    case LinkDeleted = 'link.deleted';
    case LinkClicked = 'link.clicked';
    case ConversionCreated = 'conversion.created';
    case Ping = 'ping';

    /** The events a webhook may subscribe to (everything but ping). */
    public static function subscribable(): array
    {
        return array_values(array_map(
            fn (self $event) => $event->value,
            array_filter(self::cases(), fn (self $event) => $event !== self::Ping)
        ));
    }
}
