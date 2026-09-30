<?php

namespace Tests\Feature;

use App\Models\Click;
use App\Models\Domain;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversionTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_click_gets_its_own_unguessable_token(): void
    {
        $site = Site::factory()->create();
        Link::factory()->for($site)->create(['short_code' => 'promo']);

        $this->get('/r/promo');
        $this->get('/r/promo');

        $tokens = Click::pluck('token');
        $this->assertCount(2, $tokens->unique());
        $this->assertSame(24, strlen($tokens[0]));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', $tokens[0]);
    }

    public function test_with_tracking_off_the_destination_is_left_exactly_as_it_was(): void
    {
        $site = Site::factory()->create(['conversion_tracking' => false]);
        Link::factory()->for($site)->create(['short_code' => 'promo', 'target_url' => 'https://shop.example/landing?a=1']);

        $this->get('/r/promo')->assertRedirect('https://shop.example/landing?a=1');
    }

    public function test_with_tracking_on_the_destination_is_handed_the_click_token(): void
    {
        $site = Site::factory()->create(['conversion_tracking' => true]);
        Link::factory()->for($site)->create(['short_code' => 'promo', 'target_url' => 'https://shop.example/landing?a=1#top']);

        $response = $this->get('/r/promo');

        $token = Click::sole()->token;
        $response->assertRedirect("https://shop.example/landing?a=1&lf_click={$token}#top");
    }

    public function test_the_token_in_the_redirect_is_the_one_stored_for_that_click(): void
    {
        $site = Site::factory()->create(['conversion_tracking' => true]);
        Link::factory()->for($site)->create(['short_code' => 'promo']);

        $location = $this->get('/r/promo')->headers->get('Location');

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(Click::sole()->token, $query['lf_click']);
    }

    public function test_a_branded_domain_redirect_carries_it_too(): void
    {
        $site = Site::factory()->create(['conversion_tracking' => true]);
        Domain::factory()->for($site)->verified()->create(['host' => 'go.example.com']);
        Link::factory()->for($site)->create(['short_code' => 'promo', 'target_url' => 'https://shop.example/landing']);

        $response = $this->call('GET', 'http://go.example.com/promo');

        $response->assertRedirect('https://shop.example/landing?lf_click='.Click::sole()->token);
    }

    public function test_a_password_protected_link_hands_over_the_token_only_after_the_password(): void
    {
        $site = Site::factory()->create(['conversion_tracking' => true]);
        $link = Link::factory()->for($site)->create(['short_code' => 'secret', 'target_url' => 'https://shop.example/vip']);
        $link->forceFill(['password' => bcrypt('open-sesame')])->save();

        // Showing the form is not a click, and gives nothing away.
        $this->get('/r/secret')->assertOk();
        $this->assertSame(0, Click::count());

        $this->post('/r/secret', ['password' => 'wrong'])->assertUnprocessable();
        $this->assertSame(0, Click::count());

        $response = $this->post('/r/secret', ['password' => 'open-sesame']);
        $response->assertRedirect('https://shop.example/vip?lf_click='.Click::sole()->token);
    }

    public function test_an_expired_or_inactive_link_records_nothing(): void
    {
        $site = Site::factory()->create(['conversion_tracking' => true]);
        Link::factory()->for($site)->create(['short_code' => 'off', 'is_active' => false]);
        Link::factory()->for($site)->create(['short_code' => 'old', 'expires_at' => now()->subDay()]);

        $this->get('/r/off')->assertNotFound();
        $this->get('/r/old')->assertStatus(410);

        $this->assertSame(0, Click::count());
    }

    public function test_the_redirect_does_not_cost_extra_queries_for_the_tracking_switch(): void
    {
        $site = Site::factory()->create(['conversion_tracking' => true]);
        Link::factory()->for($site)->create(['short_code' => 'promo']);

        \DB::enableQueryLog();
        $this->get('/r/promo')->assertRedirect();
        $selectsOnSites = collect(\DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'select') && str_contains($q['query'], '"sites"'))->count();

        // One (eager-loaded with the link), not one per use of the site.
        $this->assertLessThanOrEqual(2, $selectsOnSites);
    }

    public function test_site_settings_can_switch_tracking_on_and_it_defaults_to_off(): void
    {
        $user = User::factory()->create();
        $workspace = $user->defaultWorkspace();

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/sites', ['workspace_id' => $workspace->id, 'name' => 'Shop'])
            ->assertCreated()->assertJsonPath('conversion_tracking', false);

        $this->actingAs($user, 'sanctum')->putJson('/api/sites/'.$created->json('id'), ['conversion_tracking' => true])
            ->assertOk()->assertJsonPath('conversion_tracking', true);
        $this->actingAs($user, 'sanctum')->putJson('/api/sites/'.$created->json('id'), ['conversion_tracking' => 'maybe'])
            ->assertUnprocessable()->assertJsonValidationErrors('conversion_tracking');
    }
}
