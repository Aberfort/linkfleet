<?php

namespace Tests\Feature;

use App\Models\Click;
use App\Models\Conversion;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DemoSeederConversionsTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    public function test_the_demo_gets_tracking_switched_on_and_conversions_to_show(): void
    {
        $this->seedDemo();

        $this->assertSame(2, Site::where('conversion_tracking', true)->count());
        $this->assertGreaterThan(20, Conversion::count());
        $this->assertGreaterThan(0, Conversion::where('event', 'purchase')->count());
        $this->assertGreaterThan(0, Conversion::where('event', 'signup')->count());
    }

    public function test_purchases_carry_money_in_one_currency_and_signups_carry_none(): void
    {
        $this->seedDemo();

        $this->assertSame(0, Conversion::where('event', 'purchase')->whereNull('value')->count());
        $this->assertSame(['USD'], Conversion::where('event', 'purchase')->distinct()->pluck('currency')->all());
        $this->assertSame(0, Conversion::where('event', 'signup')->whereNotNull('value')->count());
    }

    public function test_every_conversion_belongs_to_a_click_that_has_a_token_and_precedes_it(): void
    {
        $this->seedDemo();

        foreach (Conversion::with('click')->get() as $conversion) {
            $this->assertNotNull($conversion->click->token);
            $this->assertSame($conversion->click->link_id, $conversion->link_id);
            $this->assertTrue($conversion->created_at->gte($conversion->click->created_at));
            $this->assertFalse($conversion->created_at->isFuture());
        }
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->seedDemo();
        $before = [Conversion::count(), Click::count(), Conversion::pluck('id')->sort()->values()->all()];

        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $this->assertSame($before, [Conversion::count(), Click::count(), Conversion::pluck('id')->sort()->values()->all()]);
    }

    public function test_clicks_seeded_before_conversions_existed_are_given_tokens_when_picked(): void
    {
        $this->seedDemo();
        // Put the database back the way production has it: clicks with no token, no conversions.
        Conversion::query()->delete();
        Click::query()->update(['token' => null]);

        $this->seed(DemoDataSeeder::class);

        $this->assertGreaterThan(20, Conversion::count());
        $this->assertSame(0, Conversion::whereHas('click', fn ($q) => $q->whereNull('token'))->count());
    }

    public function test_seeding_fires_no_webhooks(): void
    {
        Queue::fake();

        $this->seedDemo();

        Queue::assertNothingPushed();
    }

    public function test_the_demo_dashboard_reports_them(): void
    {
        $this->seedDemo();
        $demo = User::where('email', DemoUserSeeder::EMAIL)->sole();
        $site = $demo->defaultWorkspace()->sites()->where('name', 'Marketing Site')->sole();

        $this->actingAs($demo, 'sanctum')->getJson("/api/sites/{$site->id}/analytics")
            ->assertOk()
            ->assertJsonPath('conversion_tracking', true)
            ->assertJsonPath('conversions.revenue.0.currency', 'USD');
    }

    public function test_the_demo_has_traffic_from_a_spread_of_countries_and_some_unknown(): void
    {
        $this->seedDemo();

        $countries = Click::whereNotNull('country')->distinct()->pluck('country')->all();
        $this->assertGreaterThanOrEqual(6, count($countries));
        $this->assertContains('US', $countries);
        $this->assertContains('UA', $countries);
        $this->assertGreaterThan(0, Click::whereNull('country')->count(), 'real data has unknowns too');
        $this->assertSame(0, Click::whereRaw('LENGTH(country) <> 2')->count());
    }

    public function test_countries_are_only_seeded_once_and_never_over_real_data(): void
    {
        $this->seedDemo();
        $before = Click::orderBy('id')->pluck('country', 'id')->all();

        $this->seed(DemoDataSeeder::class);
        $this->assertSame($before, Click::orderBy('id')->pluck('country', 'id')->all());
    }

    public function test_clicks_seeded_before_countries_existed_get_them(): void
    {
        $this->seedDemo();
        // Production's state: clicks that predate the country column.
        Click::query()->update(['country' => null]);

        $this->seed(DemoDataSeeder::class);

        $this->assertGreaterThan(0, Click::whereNotNull('country')->count());
    }
}
