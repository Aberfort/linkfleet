<?php

namespace App\Actions;

use App\Enums\WebhookEvent;
use App\Exceptions\ConversionRejected;
use App\Models\Click;
use App\Models\Conversion;
use App\Support\WebhookDispatcher;
use App\Support\WebhookPayload;
use Illuminate\Database\UniqueConstraintViolationException;

class RecordConversion
{
    /**
     * Records that the visitor behind $click did $event. Sending the same
     * conversion twice is safe: the second call returns the first.
     *
     * @return array{0: Conversion, 1: bool} the conversion, and whether this call created it
     *
     * @throws ConversionRejected
     */
    public function handle(Click $click, string $event, ?string $value, ?string $currency, string $externalId, string $source): array
    {
        $link = $click->link()->with('site:id,workspace_id,conversion_tracking')->firstOrFail();

        if (! $link->site->conversion_tracking) {
            throw new ConversionRejected('Відстеження конверсій вимкнено для цього сайту.');
        }

        $days = (int) config('features.conversions.attribution_days');

        // An old click is more likely a stale cookie than a purchase that took
        // three months, and letting them through would make the numbers drift.
        if ($click->created_at->lt(now()->subDays($days))) {
            throw new ConversionRejected("Клік старший за {$days} днів — конверсію не зараховано.");
        }

        $key = ['click_id' => $click->id, 'event' => $event, 'external_id' => $externalId];

        if ($existing = Conversion::where($key)->first()) {
            return [$existing, false];
        }

        try {
            $conversion = Conversion::create($key + [
                'link_id' => $click->link_id,
                'value' => $value,
                'currency' => $value !== null ? $currency : null,
                'source' => $source,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two identical requests raced past the check above; the loser
            // gets what the winner wrote.
            return [Conversion::where($key)->firstOrFail(), false];
        }

        WebhookDispatcher::dispatch(WebhookEvent::ConversionCreated, $link->site_id, fn () => [
            'link' => WebhookPayload::link($link),
            'conversion' => WebhookPayload::conversion($conversion->refresh(), $click),
        ]);

        return [$conversion, true];
    }
}
