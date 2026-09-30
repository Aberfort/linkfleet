<?php

namespace App\Billing;

use Carbon\CarbonImmutable;
use Laravel\Paddle\Cashier;
use Throwable;

/**
 * Keeps subscription events in order.
 *
 * Paddle does not promise to deliver them in the order they happened, and
 * Cashier applies each as it arrives. Every subscription row therefore
 * remembers when the newest event it has applied *occurred*; an older one
 * arriving late is dropped instead of undoing a newer state.
 */
final class SubscriptionClock
{
    private const FORMAT = 'Y-m-d H:i:s.u';

    /**
     * The subscription an event is about and when it happened, or [null, null]
     * for anything that is not a subscription event we can place in time.
     * (An event with no time on it cannot be put in order, so it is simply
     * applied - parsing '' would mean "now", which is worse than nothing.)
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string|null, 1: CarbonImmutable|null}
     */
    public static function of(array $payload): array
    {
        $type = $payload['event_type'] ?? null;
        $id = $payload['data']['id'] ?? null;
        $occurredAt = $payload['occurred_at'] ?? null;

        if (! is_string($type) || ! str_starts_with($type, 'subscription.')
            || ! is_string($id) || ! is_string($occurredAt) || $occurredAt === '') {
            return [null, null];
        }

        try {
            return [$id, CarbonImmutable::parse($occurredAt, 'UTC')];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /** Whether we have already applied something that happened after $at. */
    public function isOutdated(string $subscriptionId, CarbonImmutable $at): bool
    {
        $applied = Cashier::$subscriptionModel::query()
            ->where('paddle_id', $subscriptionId)
            ->value('paddle_event_at');

        return $applied !== null && $at->lt(CarbonImmutable::parse($applied, 'UTC'));
    }

    /**
     * Records that something at $at has been applied. Only ever forward: two
     * events handled at the same moment cannot leave the clock behind.
     */
    public function advance(string $subscriptionId, CarbonImmutable $at): void
    {
        $stamp = $at->utc()->format(self::FORMAT);

        Cashier::$subscriptionModel::query()
            ->where('paddle_id', $subscriptionId)
            ->where(fn ($query) => $query->whereNull('paddle_event_at')->orWhere('paddle_event_at', '<', $stamp))
            ->update(['paddle_event_at' => $stamp]);
    }
}
