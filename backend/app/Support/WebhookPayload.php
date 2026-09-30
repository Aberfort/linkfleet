<?php

namespace App\Support;

use App\Models\Click;
use App\Models\Conversion;
use App\Models\Link;

/** The shapes receivers get. Adding a field is safe; renaming one breaks people, so don't. */
final class WebhookPayload
{
    /** @return array<string, mixed> */
    public static function link(Link $link): array
    {
        return [
            'id' => $link->id,
            'site_id' => $link->site_id,
            'short_code' => $link->short_code,
            'short_url' => $link->short_url,
            'target_url' => $link->target_url,
            'is_active' => (bool) $link->is_active,
            'clicks_count' => (int) $link->clicks_count,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'has_password' => $link->isPasswordProtected(),
            'created_at' => $link->created_at?->toIso8601String(),
            'updated_at' => $link->updated_at?->toIso8601String(),
        ];
    }

    /**
     * No IP address, hashed or otherwise: the receiver is a third party and
     * has no more business with it than the dashboard does.
     *
     * @return array<string, mixed>
     */
    public static function click(Click $click): array
    {
        return [
            // The same token the destination is handed (see ConversionUrl),
            // so a receiver can match a conversion to the click that caused it.
            'id' => $click->token,
            'occurred_at' => $click->created_at?->toIso8601String(),
            'referrer' => $click->referrer,
            'browser' => $click->browser,
            'browser_version' => $click->browser_version,
            'platform' => $click->platform,
            'device_type' => $click->device_type,
            'country' => $click->country,
        ];
    }

    /** @return array<string, mixed> */
    public static function conversion(Conversion $conversion, Click $click): array
    {
        return [
            'event' => $conversion->event,
            'value' => $conversion->value,
            'currency' => $conversion->currency,
            'external_id' => $conversion->external_id !== '' ? $conversion->external_id : null,
            'source' => $conversion->source,
            // Matches click.id in link.clicked.
            'click_id' => $click->token,
            'converted_at' => $conversion->created_at?->toIso8601String(),
        ];
    }
}
