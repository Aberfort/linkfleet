<?php

namespace Tests\Feature;

use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\WebhookSender;
use App\Support\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesDns;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use FakesDns, RefreshDatabase;

    private function make(string $url = 'https://hooks.example.com/in/abc'): Webhook
    {
        $workspace = Workspace::factory()->create();

        return $workspace->webhooks()->create(['url' => $url, 'events' => ['link.clicked']]);
    }

    private function envelope(): array
    {
        return ['id' => 'evt_1', 'type' => 'link.clicked', 'created_at' => '2026-09-30T10:00:00+00:00', 'workspace_id' => 1, 'data' => ['x' => 'é']];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePublicReceivers();
    }

    public function test_a_delivery_is_signed_and_describes_itself_in_its_headers(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $webhook = $this->make();

        $outcome = app(WebhookSender::class)->send($webhook, $this->envelope());

        Http::assertSent(function (Request $request) use ($webhook) {
            $body = $request->body();
            $signature = $request->header('LinkFleet-Signature')[0];

            return $request->url() === 'https://hooks.example.com/in/abc'
                && $request->method() === 'POST'
                && $request->header('Content-Type')[0] === 'application/json'
                && $request->header('LinkFleet-Event')[0] === 'link.clicked'
                && $request->header('LinkFleet-Delivery')[0] === 'evt_1'
                && str_starts_with($request->header('User-Agent')[0], 'LinkFleet-Webhooks/')
                // The receiver's recipe, applied to what actually went out.
                && WebhookSignature::verify($signature, $webhook->secret, $body)
                && json_decode($body, true)['data']['x'] === 'é';
        });
        $this->assertTrue($outcome->delivery->success);
        $this->assertFalse($outcome->retry);
        $this->assertSame(200, $outcome->delivery->status_code);
    }

    public function test_the_connection_is_pinned_to_the_vetted_address_and_never_follows_redirects(): void
    {
        $seen = null;
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response('ok', 200);
        });

        app(WebhookSender::class)->send($this->make(), $this->envelope());

        $this->assertSame(['hooks.example.com:443:203.0.114.7'], $seen['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($seen['allow_redirects']);
    }

    public function test_the_exact_bytes_signed_are_the_bytes_sent(): void
    {
        $sent = null;
        Http::fake(function (Request $request) use (&$sent) {
            $sent = $request;

            return Http::response('', 204);
        });
        $webhook = $this->make();

        app(WebhookSender::class)->send($webhook, $this->envelope());

        $this->assertTrue(WebhookSignature::verify($sent->header('LinkFleet-Signature')[0], $webhook->secret, $sent->body()));
    }

    public function test_the_attempt_is_written_to_the_log_with_its_payload(): void
    {
        Http::fake(['*' => Http::response('thanks', 200)]);
        $webhook = $this->make();

        $delivery = app(WebhookSender::class)->send($webhook, $this->envelope(), attempt: 3)->delivery;

        $this->assertDatabaseHas('webhook_deliveries', [
            'id' => $delivery->id, 'webhook_id' => $webhook->id, 'event' => 'link.clicked',
            'status_code' => 200, 'success' => true, 'attempt' => 3, 'response_excerpt' => 'thanks',
        ]);
        $this->assertSame('evt_1', $delivery->fresh()->payload['id']);
    }

    public function test_a_server_error_is_recorded_and_worth_retrying(): void
    {
        Http::fake(['*' => Http::response('boom', 503)]);

        $outcome = app(WebhookSender::class)->send($this->make(), $this->envelope());

        $this->assertFalse($outcome->delivery->success);
        $this->assertSame(503, $outcome->delivery->status_code);
        $this->assertSame('Отримувач відповів 503.', $outcome->delivery->error);
        $this->assertTrue($outcome->retry);
    }

    public function test_a_client_error_is_final_because_repeating_it_changes_nothing(): void
    {
        foreach ([400, 401, 403, 404, 410, 422] as $status) {
            Http::fake(['*' => Http::response('no', $status)]);

            $outcome = app(WebhookSender::class)->send($this->make(), $this->envelope());

            $this->assertFalse($outcome->delivery->success, (string) $status);
            $this->assertFalse($outcome->retry, (string) $status);
        }
    }

    public function test_a_request_timeout_or_rate_limit_is_retried(): void
    {
        foreach ([408, 429] as $status) {
            Http::fake(['*' => Http::response('later', $status)]);

            $this->assertTrue(app(WebhookSender::class)->send($this->make(), $this->envelope())->retry, (string) $status);
        }
    }

    public function test_a_redirect_is_a_failure_not_something_to_follow(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        $outcome = app(WebhookSender::class)->send($this->make(), $this->envelope());

        $this->assertFalse($outcome->delivery->success);
        $this->assertSame(302, $outcome->delivery->status_code);
        Http::assertSentCount(1);
    }

    public function test_a_connection_failure_is_recorded_readably_and_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));

        $outcome = app(WebhookSender::class)->send($this->make(), $this->envelope());

        $this->assertNull($outcome->delivery->status_code);
        $this->assertSame('Отримувач не відповів вчасно.', $outcome->delivery->error);
        $this->assertTrue($outcome->retry);
    }

    public function test_an_invalid_certificate_is_explained(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem'));

        $this->assertSame(
            'Сертифікат отримувача недійсний.',
            app(WebhookSender::class)->send($this->make(), $this->envelope())->delivery->error
        );
    }

    public function test_an_address_that_turned_internal_is_blocked_at_delivery_and_not_retried(): void
    {
        // The URL passed when it was saved; the name has since been pointed at the LAN.
        Http::fake();
        $webhook = $this->make('https://sneaky.example.com/hook');

        $outcome = app(WebhookSender::class)->send($webhook, $this->envelope());

        Http::assertNothingSent();
        $this->assertFalse($outcome->delivery->success);
        $this->assertNull($outcome->delivery->status_code);
        $this->assertStringContainsString('внутрішню мережу', $outcome->delivery->error);
        $this->assertFalse($outcome->retry);
    }

    public function test_a_reply_that_is_too_big_is_cut_off_by_curl(): void
    {
        $seen = null;
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options['curl'];

            return Http::response('ok');
        });

        app(WebhookSender::class)->send($this->make(), $this->envelope());

        $this->assertSame(1_000_000, $seen[CURLOPT_MAXFILESIZE]);
        $this->assertFalse($seen[CURLOPT_NOPROGRESS]);
        $abort = $seen[CURLOPT_XFERINFOFUNCTION];
        $this->assertSame(0, $abort(null, 0, 0, 0, 0), 'a small reply carries on');
        $this->assertSame(0, $abort(null, 0, 1_000_000, 0, 0), 'exactly at the limit carries on');
        $this->assertSame(1, $abort(null, 0, 1_000_001, 0, 0), 'past the limit is aborted');
    }

    public function test_guzzles_stream_option_is_never_used_because_it_would_drop_the_address_pin(): void
    {
        // "stream" makes Guzzle pick its PHP-streams handler instead of curl.
        // That handler has no CURLOPT_RESOLVE, so the connection would go to
        // whatever DNS says at that moment - the very thing pinning prevents.
        // Http::fake cannot show which handler would run, so pin the option.
        $seen = null;
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response('ok');
        });

        app(WebhookSender::class)->send($this->make(), $this->envelope());

        $this->assertEmpty($seen['stream'] ?? false);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $seen['curl']);
    }

    public function test_a_reply_that_is_not_valid_text_is_stored_without_breaking_the_log(): void
    {
        // Cut mid-character, plus raw binary: neither may make the insert fail.
        Http::fake(['*' => Http::response("caf\xC3".str_repeat("\x00\xFF\xFE", 10), 200)]);

        $delivery = app(WebhookSender::class)->send($this->make(), $this->envelope())->delivery;

        $this->assertTrue($delivery->success);
        $this->assertTrue(mb_check_encoding($delivery->fresh()->response_excerpt, 'UTF-8'));
    }

    public function test_only_a_bounded_excerpt_of_the_response_is_kept(): void
    {
        Http::fake(['*' => Http::response(str_repeat('a', 5000), 200)]);

        $delivery = app(WebhookSender::class)->send($this->make(), $this->envelope())->delivery;

        $this->assertSame(1000, strlen($delivery->response_excerpt));
    }

    public function test_the_log_is_trimmed_to_the_latest_hundred(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $webhook = $this->make();

        for ($i = 0; $i < 130; $i++) {
            app(WebhookSender::class)->send($webhook, $this->envelope());
        }

        // Trimmed on every 20th write, so it hovers just above the cap.
        $count = $webhook->deliveries()->count();
        $this->assertLessThanOrEqual(120, $count);
        $this->assertGreaterThanOrEqual(100, $count);
        // ...and it is the oldest that go: the newest delivery is always kept.
        $this->assertTrue($webhook->deliveries()->where('id', $webhook->deliveries()->max('id'))->exists());
        $this->assertSame(0, $webhook->deliveries()->where('id', '<=', $webhook->deliveries()->min('id') - 1)->count());
    }
}
