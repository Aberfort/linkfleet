<?php

namespace Tests\Feature;

use App\Billing\Entitlements;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Paddle\Subscription;
use Tests\Support\InteractsWithBilling;
use Tests\TestCase;

/**
 * The one door Paddle knocks on. It must let real events through and nothing
 * else - a forged "subscription created" is a free plan.
 */
class PaddleWebhookTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBilling();
    }

    /** A payer with a Paddle customer and the workspace they are about to pay for. */
    private function payerAndWorkspace(): array
    {
        $payer = User::factory()->create();
        $workspace = $payer->defaultWorkspace();

        return [$payer, $workspace, $this->customerFor($payer)->paddle_id];
    }

    private function created(string $subscriptionId, string $customerId, Workspace $workspace, string $price = 'pri_pro_m', ?string $at = null)
    {
        return $this->sendPaddleEvent($this->paddleEvent(
            'subscription.created',
            $this->subscriptionData($subscriptionId, $customerId, 'active', $price, $workspace),
            $at,
        ));
    }

    // ---- the signature ----------------------------------------------------

    public function test_a_correctly_signed_event_is_accepted(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();

        $this->created('sub_1', $customerId, $workspace)->assertOk();
    }

    public function test_an_event_with_no_signature_is_refused(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_team_m', $workspace));

        $this->postJson('/api/paddle/webhook', $event)->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_an_event_signed_with_the_wrong_secret_is_refused(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_team_m', $workspace));

        $this->sendPaddleEvent($event, secret: 'not-the-secret')->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_tampered_body_no_longer_matches_its_signature(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_pro_m', $workspace));
        $body = json_encode($event);
        $ts = time();
        $header = 'ts='.$ts.';h1='.hash_hmac('sha256', $ts.':'.$body, $this->webhookSecret);

        // Signed for a Pro, edited to a Team on the way.
        $tampered = str_replace('pri_pro_m', 'pri_team_m', $body);

        $this->call('POST', '/api/paddle/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PADDLE_SIGNATURE' => $header,
        ], $tampered)->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_captured_event_cannot_be_replayed_later(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_pro_m', $workspace));

        $this->sendPaddleEvent($event, timestamp: time() - 3600)->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_little_clock_drift_is_tolerated(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_pro_m', $workspace));

        $this->sendPaddleEvent($event, timestamp: time() - 30)->assertOk();
    }

    public function test_the_tolerance_is_configurable(): void
    {
        config(['billing.webhook_tolerance' => 5]);
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_pro_m', $workspace));

        $this->sendPaddleEvent($event, timestamp: time() - 30)->assertForbidden();
    }

    public function test_any_one_of_several_signatures_may_match_while_a_secret_is_rotated(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_pro_m', $workspace));
        $body = json_encode($event);
        $ts = time();
        $good = hash_hmac('sha256', $ts.':'.$body, $this->webhookSecret);
        $old = hash_hmac('sha256', $ts.':'.$body, 'the-previous-secret');

        $this->sendPaddleEvent($event, header: "ts={$ts};h1={$old};h1={$good}")->assertOk();
    }

    public function test_a_signature_part_we_do_not_know_is_ignored_not_fatal(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_pro_m', $workspace));
        $body = json_encode($event);
        $ts = time();
        $good = hash_hmac('sha256', $ts.':'.$body, $this->webhookSecret);

        // Paddle may add "h2" one day; Cashier alone would answer that with a 500.
        $this->sendPaddleEvent($event, header: "ts={$ts};h2=whatever;h1={$good}")->assertOk();
    }

    public function test_a_header_of_only_unknown_parts_is_refused_not_a_crash(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_pro_m', $workspace));

        $this->sendPaddleEvent($event, header: 'ts='.time().';h2=abc')->assertForbidden();
        $this->sendPaddleEvent($event, header: 'gibberish')->assertForbidden();
    }

    public function test_without_a_configured_secret_everything_is_refused_even_a_forgery(): void
    {
        // The dangerous default: with no secret an HMAC has an empty key,
        // which anyone can compute - and Cashier alone would not even check.
        config(['cashier.webhook_secret' => null]);
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_team_m', $workspace));
        $body = json_encode($event);
        $ts = time();
        $forged = 'ts='.$ts.';h1='.hash_hmac('sha256', $ts.':'.$body, '');

        $this->sendPaddleEvent($event, header: $forged)->assertStatus(503);
        $this->postJson('/api/paddle/webhook', $event)->assertStatus(503);

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_a_self_hosted_install_has_no_such_endpoint(): void
    {
        config(['billing.enabled' => false]);
        [, $workspace, $customerId] = $this->payerAndWorkspace();

        $this->created('sub_1', $customerId, $workspace)->assertNotFound();
    }

    public function test_cashiers_own_route_is_not_registered(): void
    {
        // It would answer unauthenticated when no secret is set.
        $this->postJson('/paddle/webhook', [])->assertNotFound();
    }

    public function test_a_signed_body_that_is_not_an_event_is_a_bad_request_not_a_crash(): void
    {
        $this->sendPaddleEvent(['hello' => 'world'])->assertStatus(400);
        $this->sendPaddleEvent(['event_type' => 'subscription.created'])->assertStatus(400);
        $this->sendPaddleEvent(['event_type' => ['x'], 'data' => []])->assertStatus(400);
    }

    public function test_paddles_bursts_are_not_throttled_like_ordinary_api_traffic(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();

        // The general API limit is 60 a minute per address.
        foreach (range(1, 70) as $i) {
            $this->sendPaddleEvent($this->paddleEvent('transaction.billed', ['id' => "txn_{$i}"]))->assertOk();
        }
    }

    // ---- what the events do ----------------------------------------------

    public function test_a_created_subscription_gives_the_workspace_its_plan(): void
    {
        [$payer, $workspace, $customerId] = $this->payerAndWorkspace();
        $this->assertSame('free', Entitlements::for($workspace)->plan()->key);

        $this->created('sub_1', $customerId, $workspace, 'pri_team_m')->assertOk();

        $entitlements = Entitlements::for($workspace->fresh());
        $this->assertSame('team', $entitlements->plan()->key);
        $this->assertSame('subscription', $entitlements->source());
        $subscription = Subscription::firstWhere('paddle_id', 'sub_1');
        $this->assertSame($payer->id, (int) $subscription->billable_id);
        $this->assertSame(Entitlements::subscriptionType($workspace), $subscription->type);
    }

    public function test_a_subscription_for_a_customer_we_never_created_is_ignored(): void
    {
        $workspace = Workspace::factory()->create();

        $this->created('sub_1', 'ctm_stranger', $workspace)->assertOk();

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertSame('free', Entitlements::for($workspace)->plan()->key);
    }

    public function test_the_same_event_delivered_twice_changes_nothing_more(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_pro_m', $workspace));

        $this->sendPaddleEvent($event)->assertOk();
        $this->sendPaddleEvent($event)->assertOk();

        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscription_items', 1);
    }

    public function test_cancelling_at_period_end_keeps_the_plan_until_then(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $this->created('sub_1', $customerId, $workspace)->assertOk();
        $endsAt = now()->addDays(12)->utc()->format('Y-m-d\TH:i:s.u\Z');

        $this->sendPaddleEvent($this->paddleEvent('subscription.updated', $this->subscriptionData(
            'sub_1', $customerId, 'active', 'pri_pro_m', $workspace,
            ['scheduled_change' => ['action' => 'cancel', 'effective_at' => $endsAt, 'resume_at' => null]],
        )))->assertOk();

        $entitlements = Entitlements::for($workspace->fresh());
        $this->assertSame('pro', $entitlements->plan()->key);
        $this->assertTrue($entitlements->subscription()->onGracePeriod());
    }

    public function test_when_the_cancellation_takes_effect_the_plan_goes(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $this->created('sub_1', $customerId, $workspace)->assertOk();

        $this->sendPaddleEvent($this->paddleEvent('subscription.canceled', $this->subscriptionData(
            'sub_1', $customerId, 'canceled', 'pri_pro_m', $workspace,
            ['canceled_at' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s.u\Z')],
        )))->assertOk();

        $this->assertSame('free', Entitlements::for($workspace->fresh())->plan()->key);
    }

    public function test_changing_plans_moves_the_workspace_to_the_new_one(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $this->created('sub_1', $customerId, $workspace, 'pri_pro_m')->assertOk();

        $this->sendPaddleEvent($this->paddleEvent('subscription.updated', $this->subscriptionData(
            'sub_1', $customerId, 'active', 'pri_team_m', $workspace,
        )))->assertOk();

        $this->assertSame('team', Entitlements::for($workspace->fresh())->plan()->key);
        $this->assertDatabaseCount('subscription_items', 1);
    }

    public function test_a_failed_payment_does_not_cost_the_plan_straight_away(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $this->created('sub_1', $customerId, $workspace)->assertOk();

        $this->sendPaddleEvent($this->paddleEvent('subscription.updated', $this->subscriptionData(
            'sub_1', $customerId, 'past_due', 'pri_pro_m', $workspace,
        )))->assertOk();

        $this->assertSame('pro', Entitlements::for($workspace->fresh())->plan()->key);
    }

    // ---- order ------------------------------------------------------------

    public function test_an_older_event_arriving_late_does_not_undo_a_newer_one(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $this->created('sub_1', $customerId, $workspace, at: '2026-10-01T09:00:00.000000Z')->assertOk();

        // The cancellation happened at 10:00...
        $this->sendPaddleEvent($this->paddleEvent('subscription.canceled', $this->subscriptionData(
            'sub_1', $customerId, 'canceled', 'pri_pro_m', $workspace,
            ['canceled_at' => '2026-10-01T10:00:00.000000Z'],
        ), '2026-10-01T10:00:00.000000Z'))->assertOk();
        $this->assertSame('free', Entitlements::for($workspace->fresh())->plan()->key);

        // ...and an "active" from 09:30 turns up afterwards.
        $this->sendPaddleEvent($this->paddleEvent('subscription.updated', $this->subscriptionData(
            'sub_1', $customerId, 'active', 'pri_pro_m', $workspace,
        ), '2026-10-01T09:30:00.000000Z'))->assertOk();

        $this->assertSame('free', Entitlements::for($workspace->fresh())->plan()->key, 'the stale event must not resurrect the plan');
        $this->assertSame('canceled', Subscription::firstWhere('paddle_id', 'sub_1')->status);
    }

    public function test_in_order_events_are_all_applied(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $this->created('sub_1', $customerId, $workspace, at: '2026-10-01T09:00:00.000000Z')->assertOk();

        $this->sendPaddleEvent($this->paddleEvent('subscription.updated', $this->subscriptionData(
            'sub_1', $customerId, 'active', 'pri_team_m', $workspace,
        ), '2026-10-01T09:30:00.000000Z'))->assertOk();

        $this->assertSame('team', Entitlements::for($workspace->fresh())->plan()->key);
    }

    public function test_the_clock_only_moves_forward(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $this->created('sub_1', $customerId, $workspace, at: '2026-10-01T09:00:00.000000Z')->assertOk();
        $this->sendPaddleEvent($this->paddleEvent('subscription.updated', $this->subscriptionData(
            'sub_1', $customerId, 'active', 'pri_pro_m', $workspace,
        ), '2026-10-01T12:00:00.000000Z'))->assertOk();

        // A stale one is dropped and must not drag the clock back either.
        $this->sendPaddleEvent($this->paddleEvent('subscription.updated', $this->subscriptionData(
            'sub_1', $customerId, 'paused', 'pri_pro_m', $workspace,
        ), '2026-10-01T10:00:00.000000Z'))->assertOk();

        $stored = Subscription::firstWhere('paddle_id', 'sub_1')->getRawOriginal('paddle_event_at');
        $this->assertStringStartsWith('2026-10-01 12:00:00', $stored);
    }

    public function test_an_event_with_no_time_on_it_is_still_applied(): void
    {
        [, $workspace, $customerId] = $this->payerAndWorkspace();
        $event = $this->paddleEvent('subscription.created', $this->subscriptionData('sub_1', $customerId, 'active', 'pri_pro_m', $workspace));
        unset($event['occurred_at']);

        $this->sendPaddleEvent($event)->assertOk();

        $this->assertSame('pro', Entitlements::for($workspace->fresh())->plan()->key);
    }
}
