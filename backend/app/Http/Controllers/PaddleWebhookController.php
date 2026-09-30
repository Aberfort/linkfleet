<?php

namespace App\Http\Controllers;

use App\Billing\SubscriptionClock;
use App\Http\Middleware\VerifyPaddleWebhook;
use Illuminate\Http\Request;
use Laravel\Paddle\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paddle's notifications, handled by Cashier - with two things it leaves out.
 *
 * Paddle does not deliver events in order. Cashier applies each as it comes,
 * so an old `active` arriving after `canceled` would quietly hand the plan
 * back. Every subscription event therefore carries `occurred_at`, and one
 * older than what we have already applied is dropped.
 *
 * And the signature is always checked (see VerifyPaddleWebhook).
 */
class PaddleWebhookController extends WebhookController
{
    public function __construct()
    {
        // Not calling the parent: it attaches Cashier's own check only when a
        // secret happens to be set.
        $this->middleware(VerifyPaddleWebhook::class);
    }

    public function __invoke(Request $request): Response
    {
        $payload = $request->all();

        if (! is_string($payload['event_type'] ?? null) || ! is_array($payload['data'] ?? null)) {
            return new Response('Not a Paddle event.', 400);
        }

        $clock = app(SubscriptionClock::class);
        [$subscriptionId, $occurredAt] = SubscriptionClock::of($payload);

        if ($subscriptionId !== null && $clock->isOutdated($subscriptionId, $occurredAt)) {
            return new Response('Outdated event ignored.');
        }

        $response = parent::__invoke($request);

        if ($subscriptionId !== null) {
            $clock->advance($subscriptionId, $occurredAt);
        }

        return $response;
    }
}
