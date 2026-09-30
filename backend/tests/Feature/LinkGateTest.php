<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LinkGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_link_returns_410_and_does_not_record_a_click(): void
    {
        $link = Link::factory()->for(Site::factory())->create([
            'short_code' => 'expired',
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->get('/r/expired');

        $response->assertStatus(410);
        $this->assertDatabaseCount('clicks', 0);
        $this->assertEquals(0, $link->fresh()->clicks_count);
    }

    public function test_link_with_a_future_expiry_still_redirects(): void
    {
        Link::factory()->for(Site::factory())->create([
            'short_code' => 'live',
            'target_url' => 'https://example.com/target',
            'expires_at' => now()->addDay(),
        ]);

        $this->get('/r/live')->assertRedirect('https://example.com/target');
        $this->assertDatabaseCount('clicks', 1);
    }

    public function test_password_protected_link_shows_a_gate_instead_of_redirecting(): void
    {
        $link = Link::factory()->for(Site::factory())->create(['short_code' => 'secret']);
        $link->forceFill(['password' => Hash::make('letmein')])->save();

        $response = $this->get('/r/secret');

        $response->assertOk();
        $response->assertSee('захищене паролем', false);
        // Showing the form isn't a visit.
        $this->assertDatabaseCount('clicks', 0);
    }

    public function test_correct_password_redirects_and_records_a_click(): void
    {
        $link = Link::factory()->for(Site::factory())->create([
            'short_code' => 'secret',
            'target_url' => 'https://example.com/vault',
        ]);
        $link->forceFill(['password' => Hash::make('letmein')])->save();

        $response = $this->post('/r/secret', ['password' => 'letmein']);

        $response->assertRedirect('https://example.com/vault');
        $this->assertDatabaseCount('clicks', 1);
        $this->assertEquals(1, $link->fresh()->clicks_count);
    }

    public function test_wrong_password_is_rejected_and_records_nothing(): void
    {
        $link = Link::factory()->for(Site::factory())->create(['short_code' => 'secret']);
        $link->forceFill(['password' => Hash::make('letmein')])->save();

        $response = $this->post('/r/secret', ['password' => 'wrong']);

        $response->assertStatus(422);
        $response->assertSee('Невірний пароль.', false);
        $this->assertDatabaseCount('clicks', 0);
    }

    public function test_password_hash_is_never_exposed_through_the_api(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $link = Link::factory()->for($site)->create();
        $link->forceFill(['password' => Hash::make('letmein')])->save();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/links/{$link->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('password');
        $response->assertJsonPath('has_password', true);
    }

    public function test_owner_can_set_and_then_clear_a_password(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $link = Link::factory()->for($site)->create();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/links/{$link->id}", ['password' => 'letmein'])
            ->assertOk()
            ->assertJsonPath('has_password', true);

        $this->assertTrue(Hash::check('letmein', $link->fresh()->password));

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/links/{$link->id}", ['password' => ''])
            ->assertOk()
            ->assertJsonPath('has_password', false);

        $this->assertNull($link->fresh()->password);
    }

    public function test_omitting_the_password_key_leaves_an_existing_password_alone(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $link = Link::factory()->for($site)->create();
        $link->forceFill(['password' => Hash::make('letmein')])->save();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/links/{$link->id}", ['target_url' => 'https://example.com/moved'])
            ->assertOk();

        $this->assertTrue(Hash::check('letmein', $link->fresh()->password));
    }

    public function test_expiry_in_the_past_is_rejected_on_create(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sites/{$site->id}/links", [
                'target_url' => 'https://example.com',
                'expires_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertUnprocessable();
    }
}
