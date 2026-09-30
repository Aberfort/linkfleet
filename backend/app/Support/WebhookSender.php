<?php

namespace App\Support;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Makes one delivery attempt and writes it down. Everything about talking to
 * a stranger's server is here: the SSRF guard, the pinned address, the
 * signature, the timeouts and the size cap.
 */
class WebhookSender
{
    private const KEEP_DELIVERIES = 100;

    /** What is kept of a reply. */
    private const EXCERPT_BYTES = 1000;

    /** A reply bigger than this is cut off mid-transfer. */
    private const MAX_RESPONSE_BYTES = 1_000_000;

    public function __construct(private OutboundUrlGuard $guard) {}

    /** @param  array<string, mixed>  $envelope */
    public function send(Webhook $webhook, array $envelope, int $attempt = 1): DeliveryOutcome
    {
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $started = hrtime(true);
        $status = null;
        $error = null;
        $excerpt = null;
        $retry = false;

        try {
            $target = $this->guard->resolve($webhook->url);

            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => 'LinkFleet-Webhooks/1.0',
                    'LinkFleet-Event' => $envelope['type'],
                    'LinkFleet-Delivery' => $envelope['id'],
                    'LinkFleet-Signature' => WebhookSignature::header($webhook->secret, time(), $body),
                ])
                ->withBody($body, 'application/json')
                ->withOptions([
                    'curl' => [
                        // Connect to the address we vetted, whatever DNS says now.
                        CURLOPT_RESOLVE => [$target->curlResolve()],
                        // Cut off a reply that is too big or never ends: a
                        // body with no Content-Length would otherwise be read
                        // until the timeout. Done in curl on purpose - Guzzle's
                        // own "stream" option swaps curl for a different
                        // handler, and that one ignores CURLOPT_RESOLVE, which
                        // would quietly drop the pinning above.
                        CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
                        CURLOPT_NOPROGRESS => false,
                        CURLOPT_XFERINFOFUNCTION => static fn ($handle, $downloadTotal, $downloaded) => $downloaded > self::MAX_RESPONSE_BYTES ? 1 : 0,
                    ],
                ])
                ->post($target->url);

            $status = $response->status();
            $excerpt = $this->excerptOf($response);

            if (! $response->successful()) {
                $error = "Отримувач відповів {$status}.";
                // A 4xx is the receiver saying "no" - repeating won't change
                // it, except the two that mean "not right now".
                $retry = $status >= 500 || in_array($status, [408, 429], true);
            }
        } catch (UnsafeOutboundUrl $e) {
            // Not retried: the address is not going to become safe by waiting.
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = Str::limit($this->describe($e), 500, '');
            $retry = true;
        }

        $delivery = $webhook->deliveries()->create([
            'event' => $envelope['type'],
            'payload' => $envelope,
            'status_code' => $status,
            'success' => $error === null,
            'error' => $error,
            'response_excerpt' => $excerpt,
            'attempt' => min($attempt, 255),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        $this->prune($webhook, $delivery);

        return new DeliveryOutcome($delivery, $retry);
    }

    /**
     * The start of the reply, made safe to store: cutting at a byte count can
     * split a UTF-8 character, and a binary reply is not text at all, and
     * either would make the database refuse the row.
     */
    private function excerptOf(Response $response): string
    {
        return mb_scrub(substr($response->body(), 0, self::EXCERPT_BYTES));
    }

    /** Failures are shown to the webhook's owner, so keep them readable. */
    private function describe(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'cURL error 28') => 'Отримувач не відповів вчасно.',
            str_contains($message, 'cURL error 60'), str_contains($message, 'cURL error 51') => 'Сертифікат отримувача недійсний.',
            str_contains($message, 'cURL error 7') => 'Не вдалося з\'єднатися з отримувачем.',
            default => $message,
        };
    }

    /** The log is for debugging, not history: keep the latest few. Done on a fraction of writes to stay cheap. */
    private function prune(Webhook $webhook, WebhookDelivery $delivery): void
    {
        if ($delivery->id % 20 !== 0) {
            return;
        }

        $cutoff = $webhook->deliveries()->latest('id')->skip(self::KEEP_DELIVERIES)->value('id');

        if ($cutoff !== null) {
            $webhook->deliveries()->where('id', '<=', $cutoff)->delete();
        }
    }
}
