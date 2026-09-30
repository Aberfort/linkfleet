<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use App\Support\GeoIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnalyticsRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30 15:45:00');
    }

    /** Backdates a click: created_at is not fillable and should not become so for tests' sake. */
    private function clickAt(Link $link, string $when, array $attributes = []): void
    {
        $click = $link->clicks()->create($attributes);
        $click->forceFill(['created_at' => $when])->save();
    }

    /** @return array{0: User, 1: Site, 2: Link} */
    private function scene(): array
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        return [$user, $site, Link::factory()->for($site)->create()];
    }

    public function test_the_default_window_is_the_last_thirty_days_and_says_so(): void
    {
        [$user, $site, $link] = $this->scene();
        $this->clickAt($link, '2026-09-30 10:00:00');

        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/analytics")
            ->assertOk()
            ->assertJsonPath('range', ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30])
            ->assertJsonCount(30, 'timeseries')
            ->assertJsonPath('totals.clicks', 1)
            ->assertJsonMissingPath('previous');
    }

    public function test_only_clicks_inside_the_range_are_counted_and_the_edges_are_exact(): void
    {
        [$user, $site, $link] = $this->scene();
        $this->clickAt($link, '2026-09-09 23:59:59'); // the second before
        $this->clickAt($link, '2026-09-10 00:00:00'); // first instant in
        $this->clickAt($link, '2026-09-19 23:59:59'); // last instant in
        $this->clickAt($link, '2026-09-20 00:00:00'); // the instant after

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/sites/{$site->id}/analytics?from=2026-09-10&to=2026-09-19")
            ->assertOk()
            ->assertJsonPath('range.days', 10)
            ->assertJsonPath('totals.clicks', 2)
            ->assertJsonCount(10, 'timeseries');

        $byDate = collect($response->json('timeseries'))->pluck('clicks', 'date');
        $this->assertSame(1, $byDate['2026-09-10']);
        $this->assertSame(1, $byDate['2026-09-19']);
        $this->assertSame(0, $byDate['2026-09-15'], 'quiet days are present, as zero');
    }

    public function test_breakdowns_follow_the_range_too(): void
    {
        [$user, $site, $link] = $this->scene();
        $this->clickAt($link, '2026-09-12 10:00:00', ['referrer' => 'inside.example']);
        $this->clickAt($link, '2026-08-01 10:00:00', ['referrer' => 'outside.example']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/sites/{$site->id}/analytics?from=2026-09-10&to=2026-09-19")
            ->assertJsonFragment(['label' => 'inside.example', 'clicks' => 1])
            ->assertJsonMissing(['label' => 'outside.example']);
    }

    public function test_countries_are_broken_down_and_follow_the_range(): void
    {
        [$user, $site, $link] = $this->scene();
        $this->clickAt($link, '2026-09-12 10:00:00', ['country' => 'UA']);
        $this->clickAt($link, '2026-09-13 10:00:00', ['country' => 'UA']);
        $this->clickAt($link, '2026-09-14 10:00:00', ['country' => 'DE']);
        $this->clickAt($link, '2026-09-15 10:00:00', ['country' => null]);
        $this->clickAt($link, '2026-08-01 10:00:00', ['country' => 'FR']); // outside the range

        $countries = $this->actingAs($user, 'sanctum')
            ->getJson("/api/sites/{$site->id}/analytics?from=2026-09-10&to=2026-09-19")
            ->assertOk()->json('countries');

        $this->assertSame([['label' => 'UA', 'clicks' => 2], ['label' => 'DE', 'clicks' => 1], ['label' => 'Unknown', 'clicks' => 1]], $countries);
    }

    public function test_the_report_says_whether_this_server_can_place_new_clicks_and_what_credit_to_show(): void
    {
        [$user, $site] = $this->scene();
        config(['features.geoip.database' => '/no/such/file.mmdb', 'features.geoip.attribution' => 'IP Geolocation by DB-IP']);
        $this->app->forgetInstance(GeoIp::class);

        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/analytics")
            ->assertJsonPath('geo.available', false)
            ->assertJsonPath('geo.attribution', 'IP Geolocation by DB-IP');

        config(['features.geoip.attribution' => '']);
        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/analytics")->assertJsonPath('geo.attribution', null);
    }

    public function test_visitors_count_distinct_hashed_networks_and_ignore_unknown_ones(): void
    {
        [$user, $site, $link] = $this->scene();
        $this->clickAt($link, '2026-09-12 10:00:00', ['ip_hash' => 'aaa']);
        $this->clickAt($link, '2026-09-12 11:00:00', ['ip_hash' => 'aaa']);
        $this->clickAt($link, '2026-09-13 11:00:00', ['ip_hash' => 'bbb']);
        $this->clickAt($link, '2026-09-13 12:00:00', ['ip_hash' => null]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/sites/{$site->id}/analytics?from=2026-09-10&to=2026-09-19")
            ->assertJsonPath('totals.clicks', 4)
            ->assertJsonPath('totals.visitors', 2);
    }

    public function test_comparison_brings_the_previous_period_of_equal_length(): void
    {
        [$user, $site, $link] = $this->scene();
        $this->clickAt($link, '2026-09-12 10:00:00', ['ip_hash' => 'a']);
        $this->clickAt($link, '2026-09-14 10:00:00', ['ip_hash' => 'b']);
        $this->clickAt($link, '2026-09-01 10:00:00', ['ip_hash' => 'a']); // in the previous 10 days
        $this->clickAt($link, '2026-08-01 10:00:00'); // before both

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/sites/{$site->id}/analytics?from=2026-09-10&to=2026-09-19&compare=previous")
            ->assertOk()
            ->assertJsonPath('totals.clicks', 2)
            ->assertJsonPath('previous.range', ['from' => '2026-08-31', 'to' => '2026-09-09', 'days' => 10])
            ->assertJsonPath('previous.totals.clicks', 1)
            ->assertJsonPath('previous.totals.visitors', 1)
            ->assertJsonCount(10, 'previous.timeseries');

        $this->assertSame('2026-08-31', $response->json('previous.timeseries.0.date'));
    }

    public function test_a_link_reports_its_own_range_and_comparison(): void
    {
        [$user, $site, $link] = $this->scene();
        $other = Link::factory()->for($site)->create();
        $this->clickAt($link, '2026-09-12 10:00:00');
        $this->clickAt($other, '2026-09-12 10:00:00');
        $this->clickAt($link, '2026-09-02 10:00:00');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/links/{$link->id}/analytics?from=2026-09-10&to=2026-09-19&compare=previous")
            ->assertOk()
            ->assertJsonPath('totals.clicks', 1)
            ->assertJsonPath('previous.totals.clicks', 1);
    }

    public function test_top_links_are_ranked_by_the_period_not_by_all_time(): void
    {
        [$user, $site] = $this->scene();
        $oldFavourite = Link::factory()->for($site)->create(['short_code' => 'old', 'clicks_count' => 500]);
        $newFavourite = Link::factory()->for($site)->create(['short_code' => 'new', 'clicks_count' => 3]);
        $this->clickAt($oldFavourite, '2026-01-05 10:00:00');
        $this->clickAt($newFavourite, '2026-09-12 10:00:00');
        $this->clickAt($newFavourite, '2026-09-13 10:00:00');

        $top = $this->actingAs($user, 'sanctum')
            ->getJson("/api/sites/{$site->id}/analytics?from=2026-09-10&to=2026-09-19")
            ->json('top_links');

        $this->assertSame('new', $top[0]['short_code']);
        $this->assertSame(2, $top[0]['period_clicks']);
        $this->assertSame(0, collect($top)->firstWhere('short_code', 'old')['period_clicks']);
    }

    public function test_days_is_a_shortcut_resolved_on_the_servers_clock(): void
    {
        [$user, $site, $link] = $this->scene();
        $this->clickAt($link, '2026-09-30 10:00:00');
        $this->clickAt($link, '2026-09-23 10:00:00'); // just outside the last 7 days

        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/analytics?days=7&compare=previous")
            ->assertOk()
            ->assertJsonPath('range', ['from' => '2026-09-24', 'to' => '2026-09-30', 'days' => 7])
            ->assertJsonPath('totals.clicks', 1)
            ->assertJsonPath('previous.range.to', '2026-09-23')
            ->assertJsonPath('previous.totals.clicks', 1);
    }

    public function test_days_cannot_be_combined_with_explicit_dates_or_be_out_of_bounds(): void
    {
        [$user, $site] = $this->scene();
        $as = fn () => $this->actingAs($user, 'sanctum');

        $as()->getJson("/api/sites/{$site->id}/analytics?days=7&from=2026-09-01")->assertUnprocessable();
        $as()->getJson("/api/sites/{$site->id}/analytics?days=7&to=2026-09-01")->assertUnprocessable();
        $as()->getJson("/api/sites/{$site->id}/analytics?days=0")->assertUnprocessable()->assertJsonValidationErrors('days');
        $as()->getJson("/api/sites/{$site->id}/analytics?days=367")->assertUnprocessable()->assertJsonValidationErrors('days');
        $as()->getJson("/api/sites/{$site->id}/analytics?days=abc")->assertUnprocessable()->assertJsonValidationErrors('days');
        $as()->getJson("/api/sites/{$site->id}/analytics?days=366")->assertOk()->assertJsonCount(366, 'timeseries');
    }

    public static function badRanges(): array
    {
        return [
            'end before start' => ['from=2026-09-19&to=2026-09-10', 'to'],
            'end in the future' => ['from=2026-09-10&to=2026-10-05', 'to'],
            'longer than a year' => ['from=2025-08-01&to=2026-09-30', 'to'],
            'not a date' => ['from=yesterday', 'from'],
            'wrong format' => ['from=10.09.2026&to=19.09.2026', 'from'],
            'an unknown comparison' => ['compare=year', 'compare'],
        ];
    }

    #[DataProvider('badRanges')]
    public function test_a_bad_range_is_rejected_with_the_field_at_fault(string $query, string $field): void
    {
        [$user, $site] = $this->scene();

        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/analytics?{$query}")
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_exactly_a_year_and_a_day_is_allowed_but_no_more(): void
    {
        [$user, $site] = $this->scene();
        $as = fn () => $this->actingAs($user, 'sanctum');

        // 2025-09-30 .. 2026-09-30 is 366 days inclusive.
        $as()->getJson("/api/sites/{$site->id}/analytics?from=2025-09-30&to=2026-09-30")
            ->assertOk()->assertJsonCount(366, 'timeseries');
        $as()->getJson("/api/sites/{$site->id}/analytics?from=2025-09-29&to=2026-09-30")->assertUnprocessable();
    }

    public function test_a_range_can_end_today_and_a_single_day_works(): void
    {
        [$user, $site, $link] = $this->scene();
        $this->clickAt($link, '2026-09-30 09:00:00');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/sites/{$site->id}/analytics?from=2026-09-30&to=2026-09-30&compare=previous")
            ->assertOk()->assertJsonPath('totals.clicks', 1)->assertJsonPath('previous.range.from', '2026-09-29');
    }

    public function test_a_viewer_may_read_ranged_analytics_and_a_stranger_may_not(): void
    {
        $viewer = User::factory()->create();
        $site = Site::factory()->sharedWith($viewer, WorkspaceRole::Viewer)->create();

        $this->actingAs($viewer, 'sanctum')->getJson("/api/sites/{$site->id}/analytics?from=2026-09-01&to=2026-09-10")->assertOk();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/sites/{$site->id}/analytics?from=2026-09-01&to=2026-09-10")->assertForbidden();
    }

    public function test_a_site_with_a_great_many_links_still_reports(): void
    {
        [$user, $site] = $this->scene();
        Link::factory()->for($site)->count(150)->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/analytics")->assertOk();
    }
}
