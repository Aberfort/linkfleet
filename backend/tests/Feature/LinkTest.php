<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_gets_an_auto_generated_short_code_when_none_given(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/sites/{$site->id}/links", [
            'target_url' => 'https://example.com/target',
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('short_code'));
    }

    public function test_link_accepts_a_vanity_short_code(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/sites/{$site->id}/links", [
            'target_url' => 'https://example.com/target',
            'short_code' => 'my-promo',
        ]);

        $response->assertCreated()->assertJsonPath('short_code', 'my-promo');
    }

    public function test_short_code_must_be_globally_unique(): void
    {
        $user = User::factory()->create();
        $siteA = Site::factory()->ownedBy($user)->create();
        $siteB = Site::factory()->ownedBy($user)->create();
        Link::factory()->for($siteA)->create(['short_code' => 'taken']);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/sites/{$siteB->id}/links", [
            'target_url' => 'https://example.com/target',
            'short_code' => 'taken',
        ]);

        $response->assertUnprocessable();
    }

    public function test_user_cannot_create_a_link_under_another_users_site(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $site = Site::factory()->ownedBy($owner)->create();

        $response = $this->actingAs($other, 'sanctum')->postJson("/api/sites/{$site->id}/links", [
            'target_url' => 'https://example.com/target',
        ]);

        $response->assertForbidden();
    }

    public function test_user_can_toggle_link_active_state(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $link = Link::factory()->for($site)->create(['is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/links/{$link->id}/toggle");

        $response->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_demo_user_cannot_toggle_a_link(): void
    {
        $demo = User::factory()->create(['is_demo' => true]);
        $site = Site::factory()->ownedBy($demo)->create();
        $link = Link::factory()->for($site)->create(['is_active' => true]);

        $response = $this->actingAs($demo, 'sanctum')->patchJson("/api/links/{$link->id}/toggle");

        $response->assertForbidden();
        $this->assertTrue($link->fresh()->is_active);
    }
}
