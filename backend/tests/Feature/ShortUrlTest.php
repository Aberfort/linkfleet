<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShortUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_link_uses_the_shared_route_when_its_site_has_no_domain(): void
    {
        $link = Link::factory()->for(Site::factory())->create(['short_code' => 'promo']);

        $this->assertSame(route('links.redirect', ['code' => 'promo']), $link->short_url);
    }

    public function test_a_link_uses_the_verified_domain_of_its_site(): void
    {
        $site = Site::factory()->create();
        Domain::factory()->for($site)->verified()->create(['host' => 'go.example.com']);
        $link = Link::factory()->for($site)->create(['short_code' => 'promo']);

        $this->assertSame('https://go.example.com/promo', $link->short_url);
    }

    public function test_an_unverified_domain_is_not_advertised(): void
    {
        $site = Site::factory()->create();
        Domain::factory()->for($site)->create(['host' => 'go.example.com']);
        $link = Link::factory()->for($site)->create(['short_code' => 'promo']);

        $this->assertSame(route('links.redirect', ['code' => 'promo']), $link->short_url);
    }

    public function test_the_link_list_carries_short_urls_without_leaking_the_site(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->for($user)->create();
        Domain::factory()->for($site)->verified()->create(['host' => 'go.example.com']);
        Link::factory()->for($site)->create(['short_code' => 'promo']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/sites/{$site->id}/links")
            ->assertOk()
            ->assertJsonPath('0.short_url', 'https://go.example.com/promo');

        $this->assertArrayNotHasKey('site', $response->json('0'));
    }

    public function test_listing_links_does_not_query_per_row(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->for($user)->create();
        Domain::factory()->for($site)->verified()->create();
        Link::factory()->for($site)->count(8)->create();

        DB::enableQueryLog();
        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/links")->assertOk();
        $queries = count(DB::getQueryLog());

        // Same handful of queries whether there are 8 links or 80 - what
        // matters is that it doesn't grow with the row count.
        Link::factory()->for($site)->count(20)->create();
        DB::flushQueryLog();
        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/links")->assertOk();

        $this->assertSame($queries, count(DB::getQueryLog()));
    }

    public function test_site_analytics_top_links_carry_the_branded_url(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->for($user)->create();
        Domain::factory()->for($site)->verified()->create(['host' => 'go.example.com']);
        Link::factory()->for($site)->create(['short_code' => 'promo', 'clicks_count' => 3]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/sites/{$site->id}/analytics")
            ->assertOk()
            ->assertJsonPath('top_links.0.short_url', 'https://go.example.com/promo')
            ->assertJsonMissingPath('top_links.0.password');
    }

    public function test_the_qr_endpoint_still_renders_for_a_branded_link(): void
    {
        $site = Site::factory()->create();
        Domain::factory()->for($site)->verified()->create(['host' => 'go.example.com']);
        Link::factory()->for($site)->create(['short_code' => 'promo']);

        $this->get('/qr/promo.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_the_demo_seeder_only_attaches_a_domain_when_configured(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(0, Domain::count());

        config(['features.demo_domain' => 'Go.Example.com']);
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class); // idempotent

        $domain = Domain::sole();
        $this->assertSame('go.example.com', $domain->host);
        $this->assertTrue($domain->is_verified);
        $this->assertSame('Docs Site', $domain->site->name);
    }
}
