<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_analytics_aggregates_clicks_across_its_links(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $linkA = Link::factory()->for($site)->create();
        $linkB = Link::factory()->for($site)->create();

        $linkA->clicks()->create(['referrer' => 'twitter.com', 'browser' => 'Chrome', 'device_type' => 'desktop']);
        $linkA->clicks()->create(['referrer' => 'twitter.com', 'browser' => 'Firefox', 'device_type' => 'desktop']);
        $linkB->clicks()->create(['referrer' => 'google.com', 'browser' => 'Chrome', 'device_type' => 'mobile']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/analytics");

        $response->assertOk();
        $response->assertJsonCount(30, 'timeseries');
        $response->assertJsonFragment(['label' => 'twitter.com', 'clicks' => 2]);
        $response->assertJsonFragment(['label' => 'google.com', 'clicks' => 1]);
        $response->assertJsonFragment(['label' => 'Chrome', 'clicks' => 2]);
    }

    public function test_link_analytics_only_covers_that_link(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $linkA = Link::factory()->for($site)->create();
        $linkB = Link::factory()->for($site)->create();

        $linkA->clicks()->create(['referrer' => 'twitter.com']);
        $linkB->clicks()->create(['referrer' => 'google.com']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/links/{$linkA->id}/analytics");

        $response->assertOk();
        $response->assertJsonFragment(['label' => 'twitter.com', 'clicks' => 1]);
        $response->assertJsonMissing(['label' => 'google.com']);
    }

    public function test_user_cannot_view_another_users_site_analytics(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $site = Site::factory()->ownedBy($owner)->create();

        $response = $this->actingAs($other, 'sanctum')->getJson("/api/sites/{$site->id}/analytics");

        $response->assertForbidden();
    }
}
