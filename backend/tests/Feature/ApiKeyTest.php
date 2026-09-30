<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    /** Logs in for real, so the request carries an ordinary session token. */
    private function sessionToken(User $user): string
    {
        return $user->createToken('API Token')->plainTextToken;
    }

    /** Mints a key through the API and hands back its plaintext. */
    private function mintKey(User $user, string $access = 'read', ?int $workspaceId = null, string $name = 'CI'): string
    {
        $response = $this->asToken($this->sessionToken($user))
            ->postJson('/api/api-keys', ['name' => $name, 'access' => $access, 'workspace_id' => $workspaceId])
            ->assertCreated();

        return $response->json('token');
    }

    /**
     * Requests made with a bearer token, not actingAs(): the point is that
     * Sanctum authenticates a real token. The guard is reset first because
     * it caches the previous request's user within one test.
     */
    private function asToken(string $plain): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($plain);
    }

    public function test_creating_a_key_returns_the_plaintext_once_and_stores_only_a_hash(): void
    {
        $user = User::factory()->create();

        $response = $this->asToken($this->sessionToken($user))
            ->postJson('/api/api-keys', ['name' => 'Zapier', 'access' => 'write'])
            ->assertCreated()
            ->assertJsonPath('name', 'Zapier')
            ->assertJsonPath('access', 'write')
            ->assertJsonPath('workspace_id', null);

        $plain = $response->json('token');
        [, $secret] = explode('|', $plain, 2);

        $this->assertStringStartsWith('lf_', $secret);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $secret]);
        $this->assertDatabaseHas('personal_access_tokens', ['token' => hash('sha256', $secret)]);
    }

    public function test_the_list_never_repeats_the_secret_and_leaves_session_tokens_out(): void
    {
        $user = User::factory()->create();
        $this->mintKey($user, 'read', null, 'Reporting');
        $session = $this->sessionToken($user);

        $response = $this->asToken($session)->getJson('/api/api-keys')->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame('Reporting', $response->json('0.name'));
        $this->assertArrayNotHasKey('token', $response->json('0'));
    }

    public function test_a_read_key_can_read_but_not_change_anything(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $key = $this->mintKey($user, 'read');

        $this->asToken($key)->getJson('/api/sites')->assertOk();
        $this->asToken($key)->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);

        $this->asToken($key)->postJson("/api/sites/{$site->id}/links", ['target_url' => 'https://example.com'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Цей ключ має доступ лише для читання.');
        $this->asToken($key)->deleteJson("/api/sites/{$site->id}")->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertSame(0, Link::count());
    }

    public function test_a_write_key_can_change_things_the_user_may_change(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $key = $this->mintKey($user, 'write');

        $this->asToken($key)->postJson("/api/sites/{$site->id}/links", ['target_url' => 'https://example.com'])
            ->assertCreated();
    }

    public function test_a_key_is_never_more_powerful_than_the_person_who_made_it(): void
    {
        $viewer = User::factory()->create();
        $site = Site::factory()->sharedWith($viewer, WorkspaceRole::Viewer)->create();
        $key = $this->mintKey($viewer, 'write');

        $this->asToken($key)->getJson("/api/sites/{$site->id}")->assertOk();
        $this->asToken($key)->postJson("/api/sites/{$site->id}/links", ['target_url' => 'https://example.com'])
            ->assertForbidden();
    }

    public function test_a_key_pinned_to_a_workspace_sees_only_that_workspace(): void
    {
        $user = User::factory()->create();
        $client = Workspace::factory()->withMember($user)->create(['name' => 'Client A']);
        $other = Workspace::factory()->withMember($user)->create(['name' => 'Client B']);
        $inClient = Site::factory()->create(['workspace_id' => $client->id, 'name' => 'A site']);
        $inOther = Site::factory()->create(['workspace_id' => $other->id, 'name' => 'B site']);
        $key = $this->mintKey($user, 'write', $client->id);

        $listed = $this->asToken($key)->getJson('/api/sites')->assertOk()->json();
        $this->assertSame(['A site'], array_column($listed, 'name'));
        $this->assertSame(['Client A'], array_column($this->asToken($key)->getJson('/api/workspaces')->json(), 'name'));

        $this->asToken($key)->getJson("/api/sites/{$inClient->id}")->assertOk();
        $this->asToken($key)->getJson("/api/sites/{$inOther->id}")->assertForbidden();
        $this->asToken($key)->getJson("/api/sites/{$inOther->id}/analytics")->assertForbidden();
        $this->asToken($key)->postJson('/api/sites', ['workspace_id' => $other->id, 'name' => 'Sneaky'])
            ->assertUnprocessable();

        // The same person, signed in normally, still sees both.
        $this->assertCount(2, $this->asToken($this->sessionToken($user))->getJson('/api/sites')->json());
    }

    public function test_a_key_cannot_manage_keys(): void
    {
        $user = User::factory()->create();
        $key = $this->mintKey($user, 'write');
        $id = $user->tokens()->first()->id;

        $this->asToken($key)->getJson('/api/api-keys')->assertForbidden();
        $this->asToken($key)->postJson('/api/api-keys', ['name' => 'Spawn', 'access' => 'write'])->assertForbidden();
        $this->asToken($key)->deleteJson("/api/api-keys/{$id}")->assertForbidden();
    }

    public function test_revoking_a_key_stops_it_working_immediately(): void
    {
        $user = User::factory()->create();
        $key = $this->mintKey($user);
        $this->asToken($key)->getJson('/api/sites')->assertOk();

        $id = $this->asToken($this->sessionToken($user))->getJson('/api/api-keys')->json('0.id');
        $this->asToken($this->sessionToken($user))->deleteJson("/api/api-keys/{$id}")->assertNoContent();

        $this->asToken($key)->getJson('/api/sites')->assertUnauthorized();
    }

    public function test_you_cannot_revoke_someone_elses_key_or_a_session_through_this_endpoint(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->mintKey($owner);
        $keyId = $owner->tokens()->first()->id;
        $sessionId = $other->createToken('API Token')->accessToken->id;

        $this->asToken($this->sessionToken($other))->deleteJson("/api/api-keys/{$keyId}")->assertNotFound();
        $this->asToken($this->sessionToken($other))->deleteJson("/api/api-keys/{$sessionId}")->assertNotFound();

        $this->assertNotNull(PersonalAccessToken::find($keyId));
        $this->assertNotNull(PersonalAccessToken::find($sessionId));
    }

    public function test_the_demo_account_cannot_create_keys(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $demo = User::where('email', DemoUserSeeder::EMAIL)->sole();

        $this->asToken($this->sessionToken($demo))
            ->postJson('/api/api-keys', ['name' => 'Nope', 'access' => 'read'])
            ->assertForbidden();

        $this->assertSame(0, $demo->tokens()->count() - 1); // only the session used above
    }

    public function test_validation(): void
    {
        $user = User::factory()->create();
        $strangers = Workspace::factory()->create();
        $session = $this->sessionToken($user);

        $this->asToken($session)->postJson('/api/api-keys', ['access' => 'read'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->asToken($session)->postJson('/api/api-keys', ['name' => 'x', 'access' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('access');
        $this->asToken($session)->postJson('/api/api-keys', ['name' => 'x', 'access' => 'read', 'workspace_id' => $strangers->id])
            ->assertUnprocessable()->assertJsonValidationErrors('workspace_id');
    }

    public function test_the_number_of_keys_is_capped(): void
    {
        $user = User::factory()->create();
        $session = $this->sessionToken($user);

        for ($i = 0; $i < 25; $i++) {
            $user->createToken("key {$i}", ['read']);
        }

        $this->asToken($session)->postJson('/api/api-keys', ['name' => 'One too many', 'access' => 'read'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_ordinary_session_tokens_keep_full_access(): void
    {
        $user = User::factory()->create();
        $workspace = $user->defaultWorkspace();

        $this->asToken($this->sessionToken($user))
            ->postJson('/api/sites', ['workspace_id' => $workspace->id, 'name' => 'From the UI'])
            ->assertCreated();
    }

    public function test_a_key_is_throttled_on_its_own_bucket(): void
    {
        config(['features.api_key_rate_limit' => 3, 'features.api_rate_limit' => 100]);
        $user = User::factory()->create();
        $noisy = $this->mintKey($user, 'read', null, 'noisy');
        $quiet = $this->mintKey($user, 'read', null, 'quiet');
        $session = $this->sessionToken($user);

        for ($i = 0; $i < 3; $i++) {
            $this->asToken($noisy)->getJson('/api/sites')->assertOk();
        }

        $this->asToken($noisy)->getJson('/api/sites')->assertStatus(429)->assertHeader('Retry-After');
        // A different key, and the dashboard, are unaffected.
        $this->asToken($quiet)->getJson('/api/sites')->assertOk();
        $this->asToken($session)->getJson('/api/sites')->assertOk();
    }

    public function test_sessions_are_throttled_per_user_not_per_address(): void
    {
        // Agencies sit behind one office address; one person's traffic must
        // not spend a colleague's allowance.
        config(['features.api_rate_limit' => 2]);
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceSession = $this->sessionToken($alice);
        $bobSession = $this->sessionToken($bob);

        $this->asToken($aliceSession)->getJson('/api/sites')->assertOk();
        $this->asToken($aliceSession)->getJson('/api/sites')->assertOk();
        $this->asToken($aliceSession)->getJson('/api/sites')->assertStatus(429);

        // Same address, different person.
        $this->asToken($bobSession)->getJson('/api/sites')->assertOk();
    }
}
