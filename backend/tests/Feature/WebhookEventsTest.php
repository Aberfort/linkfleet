<?php

namespace Tests\Feature;

use App\Actions\RecordConversion;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Click;
use App\Models\Domain;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookEventsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Site, 2: Webhook} */
    private function scene(array $events = ['link.created', 'link.updated', 'link.deleted', 'link.clicked']): array
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $webhook = $site->workspace->webhooks()->create(['url' => 'https://hooks.example.com/in', 'events' => $events]);

        return [$user, $site, $webhook];
    }

    /** The events queued so far, in order, as type => envelope. */
    private function queued(): array
    {
        $found = [];

        Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) use (&$found) {
            $found[] = $job->envelope;

            return true;
        });

        return $found;
    }

    private function nothingQueued(): void
    {
        Queue::assertNothingPushed();
    }

    public function test_creating_a_link_emits_link_created_with_the_link(): void
    {
        Queue::fake();
        [$user, $site, $webhook] = $this->scene();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sites/{$site->id}/links", ['target_url' => 'https://example.com/a', 'short_code' => 'promo'])
            ->assertCreated();

        Queue::assertPushed(DeliverWebhook::class, 1);
        [$envelope] = $this->queued();
        $this->assertSame('link.created', $envelope['type']);
        $this->assertStringStartsWith('evt_', $envelope['id']);
        $this->assertSame($webhook->workspace_id, $envelope['workspace_id']);
        $this->assertSame('promo', $envelope['data']['link']['short_code']);
        $this->assertSame('https://example.com/a', $envelope['data']['link']['target_url']);
        $this->assertSame($site->id, $envelope['data']['link']['site_id']);
    }

    public function test_the_job_is_addressed_to_the_webhook_that_subscribed(): void
    {
        Queue::fake();
        [$user, $site, $webhook] = $this->scene();

        Link::factory()->for($site)->create();

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->webhookId === $webhook->id);
    }

    public function test_editing_a_link_emits_link_updated(): void
    {
        [$user, $site] = $this->scene();
        $link = Link::factory()->for($site)->create(['target_url' => 'https://example.com/old']);
        Queue::fake();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/links/{$link->id}", ['target_url' => 'https://example.com/new'])
            ->assertOk();

        [$envelope] = $this->queued();
        $this->assertSame('link.updated', $envelope['type']);
        $this->assertSame('https://example.com/new', $envelope['data']['link']['target_url']);
    }

    public function test_toggling_a_link_counts_as_an_update(): void
    {
        [$user, $site] = $this->scene();
        $link = Link::factory()->for($site)->create();
        Queue::fake();

        $this->actingAs($user, 'sanctum')->patchJson("/api/links/{$link->id}/toggle")->assertOk();

        [$envelope] = $this->queued();
        $this->assertSame('link.updated', $envelope['type']);
        $this->assertFalse($envelope['data']['link']['is_active']);
    }

    public function test_deleting_a_link_emits_link_deleted_with_its_last_state(): void
    {
        [$user, $site] = $this->scene();
        $link = Link::factory()->for($site)->create(['short_code' => 'gone']);
        Queue::fake();

        $this->actingAs($user, 'sanctum')->deleteJson("/api/links/{$link->id}")->assertNoContent();

        [$envelope] = $this->queued();
        $this->assertSame('link.deleted', $envelope['type']);
        $this->assertSame('gone', $envelope['data']['link']['short_code']);
    }

    public function test_a_click_emits_link_clicked_and_nothing_else(): void
    {
        [, $site] = $this->scene();
        $link = Link::factory()->for($site)->create(['short_code' => 'promo']);
        Queue::fake();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', 'Referer' => 'https://twitter.com/some/post?secret=1'])
            ->get('/r/promo')->assertRedirect();

        // Exactly one: the click bumps clicks_count, which must not also read as an edit.
        Queue::assertPushed(DeliverWebhook::class, 1);
        [$envelope] = $this->queued();
        $this->assertSame('link.clicked', $envelope['type']);
        $this->assertSame($link->id, $envelope['data']['link']['id']);
        $this->assertSame(1, $envelope['data']['link']['clicks_count']);
        $this->assertSame('twitter.com', $envelope['data']['click']['referrer']);
        $this->assertSame('mobile', $envelope['data']['click']['device_type']);
    }

    public function test_the_click_payload_carries_no_ip_address_in_any_form(): void
    {
        [, $site] = $this->scene();
        Link::factory()->for($site)->create(['short_code' => 'promo']);
        Queue::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])->get('/r/promo');

        [$envelope] = $this->queued();
        $flat = json_encode($envelope);
        $this->assertStringNotContainsString('198.51.100', $flat);
        $this->assertStringNotContainsString('ip_hash', $flat);
        $this->assertStringNotContainsString('user_agent', $flat);
    }

    public function test_a_click_on_a_branded_domain_reports_the_branded_url(): void
    {
        [, $site] = $this->scene();
        Domain::factory()->for($site)->verified()->create(['host' => 'go.example.com']);
        Link::factory()->for($site)->create(['short_code' => 'promo']);
        Queue::fake();

        $this->call('GET', 'http://go.example.com/promo')->assertRedirect();

        [$envelope] = $this->queued();
        $this->assertSame('https://go.example.com/promo', $envelope['data']['link']['short_url']);
    }

    public function test_a_new_conversion_emits_conversion_created_with_the_click_it_came_from(): void
    {
        [, $site] = $this->scene(['conversion.created']);
        $site->update(['conversion_tracking' => true]);
        $link = Link::factory()->for($site)->create();
        $click = Click::factory()->create(['link_id' => $link->id]);
        Queue::fake();

        app(RecordConversion::class)->handle($click, 'purchase', '49.90', 'USD', 'order-1', 'server');

        Queue::assertPushed(DeliverWebhook::class, 1);
        [$envelope] = $this->queued();
        $this->assertSame('conversion.created', $envelope['type']);
        $this->assertSame($link->id, $envelope['data']['link']['id']);
        $this->assertSame('purchase', $envelope['data']['conversion']['event']);
        $this->assertSame('49.90', $envelope['data']['conversion']['value']);
        $this->assertSame('USD', $envelope['data']['conversion']['currency']);
        $this->assertSame('order-1', $envelope['data']['conversion']['external_id']);
        $this->assertSame('server', $envelope['data']['conversion']['source']);
        $this->assertSame($click->token, $envelope['data']['conversion']['click_id']);
    }

    public function test_a_repeated_conversion_is_not_announced_twice(): void
    {
        [, $site] = $this->scene(['conversion.created']);
        $site->update(['conversion_tracking' => true]);
        $click = Click::factory()->create(['link_id' => Link::factory()->for($site)->create()->id]);
        Queue::fake();

        $record = app(RecordConversion::class);
        $record->handle($click, 'signup', null, null, '', 'server');
        $record->handle($click, 'signup', null, null, '', 'server');

        Queue::assertPushed(DeliverWebhook::class, 1);
    }

    public function test_a_conversion_reaches_only_webhooks_that_asked_for_it(): void
    {
        [, $site] = $this->scene(['link.clicked']);
        $site->update(['conversion_tracking' => true]);
        $click = Click::factory()->create(['link_id' => Link::factory()->for($site)->create()->id]);
        Queue::fake();

        app(RecordConversion::class)->handle($click, 'signup', null, null, '', 'server');

        $this->nothingQueued();
    }

    public function test_the_click_event_carries_the_token_a_conversion_will_come_back_with(): void
    {
        [, $site] = $this->scene(['link.clicked']);
        Link::factory()->for($site)->create(['short_code' => 'promo']);
        Queue::fake();

        $this->get('/r/promo');

        [$envelope] = $this->queued();
        $this->assertSame(Click::sole()->token, $envelope['data']['click']['id']);
    }

    public function test_only_subscribed_events_are_sent(): void
    {
        [$user, $site] = $this->scene(['link.clicked']);
        Queue::fake();

        $link = Link::factory()->for($site)->create(['short_code' => 'promo']);
        $link->update(['target_url' => 'https://example.com/other']);
        $link->delete();

        $this->nothingQueued();
    }

    public function test_an_inactive_webhook_hears_nothing(): void
    {
        [, $site, $webhook] = $this->scene();
        $webhook->update(['is_active' => false]);
        Queue::fake();

        Link::factory()->for($site)->create();

        $this->nothingQueued();
    }

    public function test_webhooks_of_other_workspaces_hear_nothing(): void
    {
        [, $site] = $this->scene();
        $elsewhere = Workspace::factory()->create();
        $elsewhere->webhooks()->create(['url' => 'https://other.example.com/in', 'events' => ['link.created']]);
        Queue::fake();

        Link::factory()->for($site)->create();

        // Only the one in the site's own workspace.
        Queue::assertPushed(DeliverWebhook::class, 1);
    }

    public function test_every_subscribed_webhook_in_the_workspace_gets_its_own_delivery_of_the_same_event(): void
    {
        [, $site, $first] = $this->scene(['link.created']);
        $second = $site->workspace->webhooks()->create(['url' => 'https://other.example.com/in', 'events' => ['link.created']]);
        Queue::fake();

        Link::factory()->for($site)->create();

        Queue::assertPushed(DeliverWebhook::class, 2);
        $ids = collect($this->queued())->pluck('id')->unique();
        $this->assertCount(1, $ids, 'both receivers are told about the same event id');
        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->webhookId === $second->id);
    }

    public function test_importing_links_emits_one_created_event_per_row(): void
    {
        [$user, $site] = $this->scene(['link.created']);
        Queue::fake();
        $csv = UploadedFile::fake()->createWithContent('links.csv', "target_url,short_code\nhttps://example.com/1,one\nhttps://example.com/2,two\n");

        $this->actingAs($user, 'sanctum')->postJson("/api/sites/{$site->id}/links/import", ['file' => $csv])->assertOk();

        Queue::assertPushed(DeliverWebhook::class, 2);
    }

    public function test_a_workspace_with_no_webhooks_costs_one_query_and_queues_nothing(): void
    {
        $site = Site::factory()->create();
        $link = Link::factory()->for($site)->create();
        Queue::fake();

        DB::enableQueryLog();
        WebhookDispatcher::dispatch(WebhookEvent::LinkClicked, $site->id, fn () => throw new \LogicException('payload must not be built'));

        $this->assertCount(1, DB::getQueryLog());
        $this->nothingQueued();
    }

    public function test_a_failure_while_queueing_never_reaches_the_person_who_clicked(): void
    {
        [, $site] = $this->scene();
        Link::factory()->for($site)->create(['short_code' => 'promo']);
        Queue::shouldReceive('push')->andThrow(new \RuntimeException('queue is down'));

        // The redirect still happens.
        $this->get('/r/promo')->assertRedirect();
    }

    public function test_deleting_a_site_takes_its_links_without_announcing_each_one(): void
    {
        // Documented behaviour: cascades happen in the database, below the
        // model events, so only a direct link delete emits link.deleted.
        [$user, $site] = $this->scene(['link.deleted']);
        Link::factory()->for($site)->count(3)->create();
        Queue::fake();

        $this->actingAs($user, 'sanctum')->deleteJson("/api/sites/{$site->id}")->assertNoContent();

        $this->nothingQueued();
    }
}
