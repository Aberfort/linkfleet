<?php

namespace Tests\Support;

use App\Billing\Entitlements;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Paddle\Subscription;

/**
 * The hosted edition, switched on with a small catalogue whose limits are low
 * enough to hit in a test, plus the pieces of Paddle a test has to stand in
 * for: a signed webhook and the API Cashier calls.
 */
trait InteractsWithBilling
{
    protected string $webhookSecret = 'pdl_ntfset_test_secret';

    /**
     * Free: 3 links, no domains, only the owner. Pro: 10 / 2 / 3. Team: no
     * link ceiling at all (so `null` is exercised), 5 domains, 10 people.
     */
    protected function enableBilling(): void
    {
        config([
            'billing.enabled' => true,
            'billing.plans' => [
                'free' => ['name' => 'Free', 'limits' => ['links' => 3, 'domains' => 0, 'members' => 1]],
                'pro' => [
                    'name' => 'Pro',
                    'limits' => ['links' => 10, 'domains' => 2, 'members' => 3],
                    'prices' => ['monthly' => 'pri_pro_m', 'yearly' => 'pri_pro_y'],
                ],
                'team' => [
                    'name' => 'Team',
                    'limits' => ['links' => null, 'domains' => 5, 'members' => 10],
                    'prices' => ['monthly' => 'pri_team_m'],
                ],
            ],
            'cashier.api_key' => 'test_api_key',
            'cashier.client_side_token' => 'test_client_token',
            'cashier.webhook_secret' => $this->webhookSecret,
            'cashier.sandbox' => true,
        ]);
    }

    /**
     * A subscription for $workspace paid by $payer, written straight to the
     * tables Cashier keeps - the way its webhook would have.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function subscribe(
        User $payer,
        Workspace $workspace,
        string $priceId = 'pri_pro_m',
        string $status = 'active',
        array $attributes = [],
    ): Subscription {
        $this->customerFor($payer);

        $subscription = $payer->subscriptions()->create(array_merge([
            'type' => Entitlements::subscriptionType($workspace),
            'paddle_id' => 'sub_'.Str::lower(Str::random(12)),
            'status' => $status,
        ], $attributes));

        $subscription->items()->create([
            'product_id' => 'pro_01',
            'price_id' => $priceId,
            'status' => 'active',
            'quantity' => 1,
        ]);

        return $subscription->load('items');
    }

    protected function customerFor(User $user)
    {
        // Asked of the query, not the cached relation: the first call would
        // otherwise leave a null behind and the second would try to create it again.
        return $user->customer()->first() ?? $user->customer()->create([
            'paddle_id' => 'ctm_'.$user->id,
            'name' => $user->name,
            'email' => $user->email,
            'trial_ends_at' => null,
        ]);
    }

    /**
     * What Paddle puts under `data` for a subscription event, as much of it as
     * Cashier reads.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function subscriptionData(
        string $subscriptionId,
        string $customerId,
        string $status = 'active',
        string $priceId = 'pri_pro_m',
        ?Workspace $workspace = null,
        array $overrides = [],
    ): array {
        return array_merge([
            'id' => $subscriptionId,
            'status' => $status,
            'customer_id' => $customerId,
            'currency_code' => 'USD',
            'started_at' => '2026-10-01T09:00:00.000000Z',
            'next_billed_at' => '2026-11-01T09:00:00.000000Z',
            'paused_at' => null,
            'canceled_at' => null,
            'scheduled_change' => null,
            'custom_data' => $workspace ? ['subscription_type' => Entitlements::subscriptionType($workspace)] : null,
            'items' => [[
                'status' => 'active',
                'quantity' => 1,
                'price' => ['id' => $priceId, 'product_id' => 'pro_01'],
            ]],
            'management_urls' => [
                'update_payment_method' => 'https://checkout.paddle.test/update',
                'cancel' => 'https://checkout.paddle.test/cancel',
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function paddleEvent(string $type, array $data, ?string $occurredAt = null): array
    {
        return [
            'event_id' => 'evt_'.Str::lower(Str::random(12)),
            'event_type' => $type,
            'occurred_at' => $occurredAt ?? now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'notification_id' => 'ntf_'.Str::lower(Str::random(12)),
            'data' => $data,
        ];
    }

    /**
     * Posts a signed webhook the way Paddle does: `Paddle-Signature:
     * ts=<unix>;h1=<HMAC-SHA256 of "<ts>:<raw body>">`.
     *
     * @param  array<string, mixed>  $event
     */
    protected function sendPaddleEvent(array $event, ?string $secret = null, ?int $timestamp = null, ?string $header = null): TestResponse
    {
        $body = json_encode($event);
        $ts = $timestamp ?? time();
        $signature = $header ?? 'ts='.$ts.';h1='.hash_hmac('sha256', $ts.':'.$body, $secret ?? $this->webhookSecret);

        return $this->call('POST', '/api/paddle/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PADDLE_SIGNATURE' => $signature,
        ], $body);
    }

    /**
     * Stands in for Paddle's API. Cashier looks a customer up by email and
     * creates one if there is none; anything else a test needs is passed in.
     *
     * @param  array<string, mixed>  $responses  URL pattern => Http::response(...) or closure
     */
    protected function fakePaddleApi(array $responses = []): void
    {
        Http::fake(array_merge($responses, [
            'sandbox-api.paddle.com/customers*' => fn (Request $request) => Http::response(
                $request->method() === 'GET'
                    ? ['data' => []]
                    : ['data' => ['id' => 'ctm_'.Str::lower(Str::random(8)), 'name' => $request['name'] ?? '', 'email' => $request['email']]]
            ),
        ]));
    }
}
