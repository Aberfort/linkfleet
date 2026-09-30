<?php

namespace Tests\Feature;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Click;
use App\Models\Link;
use App\Models\Site;
use App\Support\GeoIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MmdbFixture;
use Tests\TestCase;

class ClickCountryTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($this->file, MmdbFixture::build(['203.0.113.0/24' => 'UA', '198.51.100.0/24' => 'DE']));
        $this->app->instance(GeoIp::class, new GeoIp($this->file));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function link(): Link
    {
        return Link::factory()->for(Site::factory()->create())->create(['short_code' => 'promo']);
    }

    private function visitFrom(string $ip): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/r/promo')->assertRedirect();
    }

    public function test_a_click_is_placed_in_the_visitors_country(): void
    {
        $this->link();

        $this->visitFrom('203.0.113.9');
        $this->visitFrom('198.51.100.9');

        $this->assertSame(['UA', 'DE'], Click::orderBy('id')->pluck('country')->all());
    }

    public function test_behind_a_proxy_it_is_the_forwarded_address_that_counts(): void
    {
        $this->link();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.44'])
            ->get('/r/promo')->assertRedirect();

        $this->assertSame('DE', Click::sole()->country);
    }

    public function test_the_address_itself_is_kept_nowhere(): void
    {
        $this->link();

        $this->visitFrom('203.0.113.9');

        $row = json_encode(\DB::table('clicks')->first());
        $this->assertStringNotContainsString('203.0.113', $row);
        $this->assertNotNull(Click::sole()->ip_hash);
    }

    public function test_an_address_the_database_does_not_know_leaves_the_country_empty(): void
    {
        $this->link();

        $this->visitFrom('8.8.8.8');

        $this->assertNull(Click::sole()->country);
    }

    public function test_with_no_database_the_click_and_the_redirect_are_unaffected(): void
    {
        $this->app->instance(GeoIp::class, new GeoIp('/no/such/country.mmdb'));
        $this->link();

        $this->visitFrom('203.0.113.9');

        $click = Click::sole();
        $this->assertNull($click->country);
        $this->assertNotNull($click->ip_hash);
        $this->assertNotNull($click->token);
    }

    public function test_a_broken_database_never_costs_anyone_their_redirect(): void
    {
        file_put_contents($this->file, 'garbage');
        $this->app->instance(GeoIp::class, new GeoIp($this->file));
        $this->link();

        $this->visitFrom('203.0.113.9');

        $this->assertSame(1, Click::count());
    }

    public function test_the_click_webhook_carries_the_country_and_still_no_address(): void
    {
        $site = Site::factory()->create();
        $site->workspace->webhooks()->create(['url' => 'https://hooks.example.com/in', 'events' => [WebhookEvent::LinkClicked->value]]);
        Link::factory()->for($site)->create(['short_code' => 'promo']);
        Queue::fake();

        $this->visitFrom('203.0.113.9');

        Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) {
            $this->assertSame('UA', $job->envelope['data']['click']['country']);
            $this->assertStringNotContainsString('203.0.113', json_encode($job->envelope));

            return true;
        });
    }
}
