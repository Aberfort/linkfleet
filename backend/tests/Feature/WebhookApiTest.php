<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\WebhookSignature;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesDns;
use Tests\TestCase;

class WebhookApiTest extends TestCase
{
    use FakesDns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePublicReceivers();
    }

    /** @return array{0: User, 1: Workspace} */
    private function ownerWithWorkspace(): array
    {
        $owner = User::factory()->create();

        return [$owner, Workspace::factory()->withMember($owner)->create()];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['url' => 'https://hooks.example.com/in', 'events' => ['link.clicked', 'link.created']], $overrides);
    }

    public function test_an_owner_creates_a_webhook_and_sees_its_secret_once(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $created = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/webhooks", $this->payload())
            ->assertCreated()
            ->assertJsonPath('url', 'https://hooks.example.com/in')
            ->assertJsonPath('events', ['link.clicked', 'link.created'])
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('latest_delivery', null);

        $secret = $created->json('secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertGreaterThanOrEqual(40, strlen($secret));

        // Never again: not in the list, not on a single fetch.
        $id = $created->json('id');
        $list = $this->actingAs($owner, 'sanctum')->getJson("/api/workspaces/{$workspace->id}/webhooks")->assertOk();
        $this->assertArrayNotHasKey('secret', $list->json('0'));
        $this->assertArrayNotHasKey('secret', $this->actingAs($owner, 'sanctum')->getJson("/api/webhooks/{$id}")->json());
    }

    public function test_the_secret_is_encrypted_in_the_database_but_readable_by_the_app(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $secret = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/webhooks", $this->payload())->json('secret');

        $raw = \DB::table('webhooks')->value('secret');
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertSame($secret, Webhook::first()->secret);
    }

    public function test_only_owners_may_touch_webhooks_even_to_read_them(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());

        foreach ([WorkspaceRole::Editor, WorkspaceRole::Viewer, null] as $role) {
            $user = User::factory()->create();
            $role && $workspace->members()->attach($user, ['role' => $role->value]);
            $as = fn () => $this->actingAs($user, 'sanctum');

            $as()->getJson("/api/workspaces/{$workspace->id}/webhooks")->assertForbidden();
            $as()->postJson("/api/workspaces/{$workspace->id}/webhooks", $this->payload())->assertForbidden();
            $as()->getJson("/api/webhooks/{$webhook->id}")->assertForbidden();
            $as()->putJson("/api/webhooks/{$webhook->id}", ['is_active' => false])->assertForbidden();
            $as()->deleteJson("/api/webhooks/{$webhook->id}")->assertForbidden();
            $as()->postJson("/api/webhooks/{$webhook->id}/rotate-secret")->assertForbidden();
            $as()->postJson("/api/webhooks/{$webhook->id}/test")->assertForbidden();
            $as()->getJson("/api/webhooks/{$webhook->id}/deliveries")->assertForbidden();
        }

        $this->assertTrue($webhook->fresh()->is_active);
    }

    public function test_an_address_pointing_inside_is_refused_when_saving(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/webhooks", $this->payload(['url' => 'https://sneaky.example.com/x']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        foreach (['http://hooks.example.com/x', 'https://169.254.169.254/latest/meta-data/', 'https://localhost/x', 'https://nowhere.example.com/x', 'file:///etc/passwd', 'not a url'] as $url) {
            $this->actingAs($owner, 'sanctum')
                ->postJson("/api/workspaces/{$workspace->id}/webhooks", $this->payload(['url' => $url]))
                ->assertUnprocessable()->assertJsonValidationErrors('url');
        }

        $this->assertSame(0, Webhook::count());
    }

    public function test_the_same_check_applies_when_changing_the_address(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/webhooks/{$webhook->id}", ['url' => 'https://sneaky.example.com/x'])
            ->assertUnprocessable();

        $this->assertSame('https://hooks.example.com/in', $webhook->fresh()->url);
    }

    public function test_events_are_validated(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $as = fn () => $this->actingAs($owner, 'sanctum');

        $as()->postJson("/api/workspaces/{$workspace->id}/webhooks", $this->payload(['events' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('events');
        $as()->postJson("/api/workspaces/{$workspace->id}/webhooks", $this->payload(['events' => ['link.exploded']]))
            ->assertUnprocessable()->assertJsonValidationErrors('events.0');
        // ping is what the test button sends, not something to subscribe to.
        $as()->postJson("/api/workspaces/{$workspace->id}/webhooks", $this->payload(['events' => ['ping']]))
            ->assertUnprocessable()->assertJsonValidationErrors('events.0');
        $as()->postJson("/api/workspaces/{$workspace->id}/webhooks", ['url' => 'https://hooks.example.com/in'])
            ->assertUnprocessable()->assertJsonValidationErrors('events');
    }

    public function test_a_workspace_is_limited_in_how_many_webhooks_it_can_have(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        config(['features.webhooks.max_per_workspace' => 2]);
        $workspace->webhooks()->create($this->payload());
        $workspace->webhooks()->create($this->payload());

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->id}/webhooks", $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    public function test_updating_changes_only_what_was_sent_and_keeps_the_secret(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());
        $secret = $webhook->secret;

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/webhooks/{$webhook->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('url', 'https://hooks.example.com/in');
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/webhooks/{$webhook->id}", ['events' => ['link.deleted'], 'url' => 'https://other.example.com/in'])
            ->assertOk();

        $fresh = $webhook->fresh();
        $this->assertSame(['link.deleted'], $fresh->events);
        $this->assertSame('https://other.example.com/in', $fresh->url);
        $this->assertSame($secret, $fresh->secret);
    }

    public function test_rotating_replaces_the_secret_and_returns_the_new_one_once(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());
        $old = $webhook->secret;

        $response = $this->actingAs($owner, 'sanctum')->postJson("/api/webhooks/{$webhook->id}/rotate-secret")->assertOk();

        $new = $response->json('secret');
        $this->assertNotSame($old, $new);
        $this->assertSame($new, $webhook->fresh()->secret);
    }

    public function test_deleting_removes_the_webhook_and_its_log(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());
        $webhook->deliveries()->create(['event' => 'ping', 'payload' => [], 'success' => true]);

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/webhooks/{$webhook->id}")->assertNoContent();

        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
        $this->assertSame(0, \DB::table('webhook_deliveries')->count());
    }

    public function test_the_list_shows_how_the_latest_delivery_went(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());
        $webhook->deliveries()->create(['event' => 'link.clicked', 'payload' => [], 'success' => true, 'status_code' => 200]);
        $webhook->deliveries()->create(['event' => 'link.created', 'payload' => [], 'success' => false, 'status_code' => 500]);

        $this->actingAs($owner, 'sanctum')->getJson("/api/workspaces/{$workspace->id}/webhooks")
            ->assertJsonPath('0.latest_delivery.success', false)
            ->assertJsonPath('0.latest_delivery.status_code', 500)
            ->assertJsonPath('0.latest_delivery.event', 'link.created');
    }

    public function test_the_test_button_sends_a_signed_ping_now_and_reports_the_result(): void
    {
        Http::fake(['*' => Http::response('pong', 200)]);
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload(['events' => ['link.created']]));

        $this->actingAs($owner, 'sanctum')->postJson("/api/webhooks/{$webhook->id}/test")
            ->assertOk()
            ->assertJsonPath('event', 'ping')
            ->assertJsonPath('success', true)
            ->assertJsonPath('status_code', 200);

        Http::assertSent(fn ($request) => WebhookSignature::verify($request->header('LinkFleet-Signature')[0], $webhook->secret, $request->body())
            && json_decode($request->body(), true)['type'] === 'ping');
    }

    public function test_a_failing_receiver_is_reported_not_raised(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());

        $this->actingAs($owner, 'sanctum')->postJson("/api/webhooks/{$webhook->id}/test")
            ->assertOk()->assertJsonPath('success', false)->assertJsonPath('status_code', 500);
    }

    public function test_the_delivery_log_is_newest_first_and_capped_at_fifty(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());
        for ($i = 1; $i <= 60; $i++) {
            $webhook->deliveries()->create(['event' => 'link.clicked', 'payload' => ['n' => $i], 'success' => true, 'status_code' => 200]);
        }

        $log = $this->actingAs($owner, 'sanctum')->getJson("/api/webhooks/{$webhook->id}/deliveries")->assertOk()->json();

        $this->assertCount(50, $log);
        $this->assertSame(60, $log[0]['payload']['n']);
        $this->assertSame(11, $log[49]['payload']['n']);
    }

    public function test_one_workspaces_webhook_is_invisible_to_another_workspaces_owner(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());
        [$intruder] = $this->ownerWithWorkspace();

        $this->actingAs($intruder, 'sanctum')->getJson("/api/webhooks/{$webhook->id}")->assertForbidden();
        $this->actingAs($intruder, 'sanctum')->getJson("/api/webhooks/{$webhook->id}/deliveries")->assertForbidden();
    }

    public function test_the_demo_account_can_look_but_not_change(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $demo = User::where('email', DemoUserSeeder::EMAIL)->sole();
        $workspace = $demo->defaultWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());
        $as = fn () => $this->actingAs($demo, 'sanctum');

        $as()->getJson("/api/workspaces/{$workspace->id}/webhooks")->assertOk();
        $as()->getJson("/api/webhooks/{$webhook->id}/deliveries")->assertOk();
        $as()->postJson("/api/workspaces/{$workspace->id}/webhooks", $this->payload())->assertForbidden();
        $as()->putJson("/api/webhooks/{$webhook->id}", ['is_active' => false])->assertForbidden();
        $as()->deleteJson("/api/webhooks/{$webhook->id}")->assertForbidden();
        // Not even the test button: it makes the server call out.
        $as()->postJson("/api/webhooks/{$webhook->id}/test")->assertForbidden();
        $as()->postJson("/api/webhooks/{$webhook->id}/rotate-secret")->assertForbidden();
    }

    public function test_a_read_only_api_key_cannot_use_the_test_button(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $webhook = $workspace->webhooks()->create($this->payload());
        $key = $owner->createToken('ci', ['read'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($key)->getJson("/api/workspaces/{$workspace->id}/webhooks")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($key)->postJson("/api/webhooks/{$webhook->id}/test")->assertForbidden();
    }
}
