<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Http\Middleware\VerifyWebhookSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier's signature check, made safe to leave on.
 *
 * Cashier only attaches its check `if (config('cashier.webhook_secret'))`.
 * Without a secret the endpoint takes any body from anyone - and a forged
 * `subscription.created` for your own workspace is a free Team plan. Worse,
 * the HMAC of an empty key is something every visitor can compute. So a
 * missing secret refuses everything (503, loudly) instead of allowing it.
 *
 * It also stops a header with a part Cashier has never heard of from
 * crashing the request: Paddle may add hash versions one day.
 */
class VerifyPaddleWebhook extends VerifyWebhookSignature
{
    public function __construct()
    {
        $this->maximumVariance = (int) config('billing.webhook_tolerance', 60);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (blank(config('cashier.webhook_secret'))) {
            Log::critical('A Paddle webhook arrived but PADDLE_WEBHOOK_SECRET is not set; refusing it.');

            abort(503, 'Billing webhooks are not configured.');
        }

        return parent::handle($request, $next);
    }

    /**
     * Only `ts` and `h1` mean anything to us; anything else is ignored.
     *
     * @return array{0: int, 1: array<string, array<int, string>>}
     */
    public function parseSignature(string $header): array
    {
        $timestamp = 0;
        $hashes = [];

        foreach (explode(';', $header) as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);

            if ($key === 'ts') {
                $timestamp = (int) $value;
            } elseif ($key === 'h1') {
                // More than one while Paddle rotates a secret; any may match.
                $hashes['h1'][] = $value;
            }
        }

        return [$timestamp, $hashes];
    }
}
