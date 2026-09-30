<?php

namespace Tests\Feature;

use App\Billing\SubscriptionClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Paddle\Subscription;
use Tests\Support\InteractsWithBilling;
use Tests\TestCase;

class SubscriptionClockTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    private function stored(Subscription $subscription): ?string
    {
        return $subscription->fresh()->getRawOriginal('paddle_event_at');
    }

    public function test_it_only_ever_moves_forward(): void
    {
        $this->enableBilling();
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $user->defaultWorkspace());
        $clock = new SubscriptionClock;

        $clock->advance($subscription->paddle_id, CarbonImmutable::parse('2026-10-01T12:00:00.500000Z'));
        $clock->advance($subscription->paddle_id, CarbonImmutable::parse('2026-10-01T10:00:00Z')); // earlier: ignored

        $this->assertSame('2026-10-01 12:00:00.500000', $this->stored($subscription));

        $clock->advance($subscription->paddle_id, CarbonImmutable::parse('2026-10-01T12:00:01Z'));

        $this->assertSame('2026-10-01 12:00:01.000000', $this->stored($subscription));
    }

    public function test_it_keeps_the_microseconds_paddle_sends(): void
    {
        $this->enableBilling();
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $user->defaultWorkspace());
        $clock = new SubscriptionClock;
        $clock->advance($subscription->paddle_id, CarbonImmutable::parse('2026-10-01T12:00:00.000200Z'));

        // Two events in the same second are still told apart.
        $this->assertTrue($clock->isOutdated($subscription->paddle_id, CarbonImmutable::parse('2026-10-01T12:00:00.000100Z')));
        $this->assertFalse($clock->isOutdated($subscription->paddle_id, CarbonImmutable::parse('2026-10-01T12:00:00.000300Z')));
    }

    public function test_a_subscription_that_has_seen_nothing_has_nothing_outdated(): void
    {
        $this->enableBilling();
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $user->defaultWorkspace());

        $this->assertFalse((new SubscriptionClock)->isOutdated($subscription->paddle_id, CarbonImmutable::parse('2001-01-01T00:00:00Z')));
    }

    public function test_an_event_at_exactly_the_recorded_moment_is_not_outdated(): void
    {
        // A redelivery of the very same event: harmless to apply again.
        $this->enableBilling();
        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $user->defaultWorkspace());
        $clock = new SubscriptionClock;
        $at = CarbonImmutable::parse('2026-10-01T12:00:00Z');
        $clock->advance($subscription->paddle_id, $at);

        $this->assertFalse($clock->isOutdated($subscription->paddle_id, $at));
    }

    public function test_only_subscription_events_are_placed_in_time(): void
    {
        $good = ['event_type' => 'subscription.updated', 'occurred_at' => '2026-10-01T12:00:00Z', 'data' => ['id' => 'sub_1']];

        $this->assertSame('sub_1', SubscriptionClock::of($good)[0]);
        $this->assertSame([null, null], SubscriptionClock::of(['event_type' => 'transaction.completed'] + $good));
        $this->assertSame([null, null], SubscriptionClock::of(['occurred_at' => ''] + $good));
        $this->assertSame([null, null], SubscriptionClock::of(['occurred_at' => 'not a date'] + $good));
        $this->assertSame([null, null], SubscriptionClock::of(['occurred_at' => null] + $good));
        $this->assertSame([null, null], SubscriptionClock::of(['data' => ['id' => 12]] + $good));
        $this->assertSame([null, null], SubscriptionClock::of(['event_type' => ['x']] + $good));
    }
}
