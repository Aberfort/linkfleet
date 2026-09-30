<?php

namespace Tests\Feature;

use App\Models\Click;
use App\Models\Conversion;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsConversionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30 15:45:00');
    }

    /** @return array{0: User, 1: Site, 2: Link} */
    private function scene(): array
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create(['conversion_tracking' => true]);

        return [$user, $site, Link::factory()->for($site)->create(['short_code' => 'promo'])];
    }

    private function clickAt(Link $link, string $when): Click
    {
        $click = Click::factory()->create(['link_id' => $link->id]);
        $click->forceFill(['created_at' => $when])->save();

        return $click;
    }

    private function convertAt(Click $click, string $when, array $attributes = []): Conversion
    {
        $conversion = Conversion::factory()->create(['click_id' => $click->id] + $attributes);
        $conversion->forceFill(['created_at' => $when])->save();

        return $conversion;
    }

    private function report(User $user, Site $site, string $query = 'from=2026-09-10&to=2026-09-19')
    {
        return $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/analytics?{$query}")->assertOk();
    }

    public function test_it_reports_conversions_in_the_range_and_says_whether_tracking_is_on(): void
    {
        [$user, $site, $link] = $this->scene();
        $click = $this->clickAt($link, '2026-09-12 10:00:00');
        $this->convertAt($click, '2026-09-12 10:05:00', ['event' => 'signup']);
        $this->convertAt($click, '2026-09-09 23:59:59', ['event' => 'signup', 'external_id' => 'before']);
        $this->convertAt($click, '2026-09-20 00:00:00', ['event' => 'signup', 'external_id' => 'after']);

        $this->report($user, $site)
            ->assertJsonPath('conversion_tracking', true)
            ->assertJsonPath('site_id', $site->id)
            ->assertJsonPath('conversions.total', 1)
            ->assertJsonPath('conversions.by_event.0.event', 'signup');
    }

    public function test_a_site_with_tracking_off_still_answers_and_says_so(): void
    {
        [$user, $site] = $this->scene();
        $site->update(['conversion_tracking' => false]);

        $this->report($user, $site)->assertJsonPath('conversion_tracking', false)->assertJsonPath('conversions.total', 0)
            ->assertJsonPath('conversions.rate', null)->assertJsonPath('conversions.revenue', []);
    }

    public function test_the_rate_counts_clicks_that_converted_once_however_many_orders_they_made(): void
    {
        [$user, $site, $link] = $this->scene();
        $buyer = $this->clickAt($link, '2026-09-12 10:00:00');
        $this->clickAt($link, '2026-09-12 11:00:00');
        $this->clickAt($link, '2026-09-12 12:00:00');
        $this->clickAt($link, '2026-09-12 13:00:00');
        // One click, three orders: three conversions, one converted click.
        foreach (['a', 'b', 'c'] as $order) {
            $this->convertAt($buyer, '2026-09-13 10:00:00', ['event' => 'purchase', 'external_id' => $order]);
        }

        $this->report($user, $site)
            ->assertJsonPath('totals.clicks', 4)
            ->assertJsonPath('conversions.total', 3)
            ->assertJsonPath('conversions.rate', 0.25);
    }

    public function test_the_rate_never_exceeds_a_hundred_percent(): void
    {
        // The click came before the range; only the conversion is in it.
        [$user, $site, $link] = $this->scene();
        $old = $this->clickAt($link, '2026-08-01 10:00:00');
        $this->convertAt($old, '2026-09-12 10:00:00');

        $this->report($user, $site)->assertJsonPath('totals.clicks', 0)->assertJsonPath('conversions.total', 1)
            ->assertJsonPath('conversions.rate', null);

        $this->clickAt($link, '2026-09-12 09:00:00');
        // Two more clicks from before the range convert inside it: 3 converted
        // clicks against 1 click in the range would read as 300%.
        $this->convertAt($this->clickAt($link, '2026-08-02 10:00:00'), '2026-09-13 10:00:00');
        $this->convertAt($this->clickAt($link, '2026-08-03 10:00:00'), '2026-09-14 10:00:00');

        $this->report($user, $site)->assertJsonPath('totals.clicks', 1)->assertJsonPath('conversions.total', 3)
            ->assertJsonPath('conversions.rate', 1);
    }

    public function test_revenue_is_summed_within_each_currency_and_never_across_them(): void
    {
        [$user, $site, $link] = $this->scene();
        $click = $this->clickAt($link, '2026-09-12 10:00:00');
        $this->convertAt($click, '2026-09-12 11:00:00', ['event' => 'purchase', 'external_id' => '1', 'value' => '10.10', 'currency' => 'USD']);
        $this->convertAt($click, '2026-09-12 11:00:00', ['event' => 'purchase', 'external_id' => '2', 'value' => '20.20', 'currency' => 'USD']);
        $this->convertAt($click, '2026-09-12 11:00:00', ['event' => 'purchase', 'external_id' => '3', 'value' => '5.00', 'currency' => 'EUR']);
        $this->convertAt($click, '2026-09-12 11:00:00', ['event' => 'signup']); // no money

        $revenue = $this->report($user, $site)->json('conversions.revenue');

        $this->assertSame([['currency' => 'USD', 'amount' => 30.3], ['currency' => 'EUR', 'amount' => 5]], $revenue);
        $this->report($user, $site)->assertJsonPath('conversions.total', 4);
    }

    public function test_events_are_broken_down_with_their_own_counts_and_revenue(): void
    {
        [$user, $site, $link] = $this->scene();
        $click = $this->clickAt($link, '2026-09-12 10:00:00');
        $this->convertAt($click, '2026-09-12 11:00:00', ['event' => 'signup']);
        $this->convertAt($click, '2026-09-12 11:00:00', ['event' => 'purchase', 'external_id' => '1', 'value' => '40.00', 'currency' => 'USD']);
        $this->convertAt($click, '2026-09-12 11:00:00', ['event' => 'purchase', 'external_id' => '2', 'value' => '60.00', 'currency' => 'USD']);

        $events = collect($this->report($user, $site)->json('conversions.by_event'))->keyBy('event');

        $this->assertSame(2, $events['purchase']['conversions']);
        $this->assertSame([['currency' => 'USD', 'amount' => 100]], $events['purchase']['revenue']);
        $this->assertSame(1, $events['signup']['conversions']);
        $this->assertSame([], $events['signup']['revenue']);
        $this->assertSame('purchase', $events->keys()->first(), 'busiest event first');
    }

    public function test_conversions_of_other_sites_are_not_counted(): void
    {
        [$user, $site, $link] = $this->scene();
        $this->convertAt($this->clickAt($link, '2026-09-12 10:00:00'), '2026-09-12 11:00:00');
        $foreign = Link::factory()->for(Site::factory()->create(['conversion_tracking' => true]))->create();
        $this->convertAt($this->clickAt($foreign, '2026-09-12 10:00:00'), '2026-09-12 11:00:00');

        $this->report($user, $site)->assertJsonPath('conversions.total', 1);
    }

    public function test_comparison_carries_the_previous_periods_conversions(): void
    {
        [$user, $site, $link] = $this->scene();
        $now = $this->clickAt($link, '2026-09-12 10:00:00');
        $this->convertAt($now, '2026-09-12 11:00:00', ['value' => '10.00', 'currency' => 'USD']);
        $before = $this->clickAt($link, '2026-09-02 10:00:00');
        $this->clickAt($link, '2026-09-03 10:00:00');
        $this->convertAt($before, '2026-09-02 11:00:00', ['event' => 'purchase', 'external_id' => '1', 'value' => '99.00', 'currency' => 'USD']);
        $this->convertAt($before, '2026-09-02 12:00:00', ['event' => 'purchase', 'external_id' => '2', 'value' => '1.00', 'currency' => 'USD']);

        $this->report($user, $site, 'from=2026-09-10&to=2026-09-19&compare=previous')
            ->assertJsonPath('previous.conversions.total', 2)
            ->assertJsonPath('previous.conversions.rate', 0.5)
            ->assertJsonPath('previous.conversions.revenue', [['currency' => 'USD', 'amount' => 100]])
            ->assertJsonMissingPath('previous.conversions.by_event');
    }

    public function test_top_links_show_what_each_converted_and_earned(): void
    {
        [$user, $site, $link] = $this->scene();
        $quiet = Link::factory()->for($site)->create(['short_code' => 'quiet']);
        $click = $this->clickAt($link, '2026-09-12 10:00:00');
        $this->clickAt($quiet, '2026-09-12 10:00:00');
        $this->convertAt($click, '2026-09-12 11:00:00', ['event' => 'purchase', 'external_id' => '1', 'value' => '25.00', 'currency' => 'USD']);
        $this->convertAt($click, '2026-09-12 11:00:00', ['event' => 'signup']);

        $top = collect($this->report($user, $site)->json('top_links'))->keyBy('short_code');

        $this->assertSame(2, $top['promo']['period_conversions']);
        $this->assertSame([['currency' => 'USD', 'amount' => 25]], $top['promo']['period_revenue']);
        $this->assertSame(0, $top['quiet']['period_conversions']);
        $this->assertSame([], $top['quiet']['period_revenue']);
    }

    public function test_top_links_do_not_cost_queries_per_link(): void
    {
        [$user, $site, $link] = $this->scene();
        Link::factory()->for($site)->count(3)->create();
        $this->convertAt($this->clickAt($link, '2026-09-12 10:00:00'), '2026-09-12 11:00:00');

        \DB::enableQueryLog();
        $this->report($user, $site);
        $few = count(\DB::getQueryLog());

        Link::factory()->for($site)->count(6)->create();
        \DB::flushQueryLog();
        $this->report($user, $site);

        $this->assertSame($few, count(\DB::getQueryLog()));
    }

    public function test_a_single_link_reports_its_own_conversions(): void
    {
        [$user, $site, $link] = $this->scene();
        $other = Link::factory()->for($site)->create();
        $this->convertAt($this->clickAt($link, '2026-09-12 10:00:00'), '2026-09-12 11:00:00');
        $this->convertAt($this->clickAt($other, '2026-09-12 10:00:00'), '2026-09-12 11:00:00');
        $this->convertAt($this->clickAt($other, '2026-09-12 10:00:00'), '2026-09-12 12:00:00');

        $this->actingAs($user, 'sanctum')->getJson("/api/links/{$link->id}/analytics?from=2026-09-10&to=2026-09-19")
            ->assertOk()->assertJsonPath('conversions.total', 1)->assertJsonPath('conversion_tracking', true)
            ->assertJsonPath('site_id', $site->id);
    }

    public function test_the_conversions_export_lists_them_without_the_click_token(): void
    {
        [$user, $site, $link] = $this->scene();
        $click = $this->clickAt($link, '2026-09-12 10:00:00');
        $this->convertAt($click, '2026-09-12 11:00:00', ['event' => 'purchase', 'external_id' => 'order-1', 'value' => '49.90', 'currency' => 'USD', 'source' => 'pixel']);
        $this->convertAt($this->clickAt($link, '2026-09-30 10:00:00'), '2026-09-30 11:00:00', ['event' => 'outside']);

        $response = $this->actingAs($user, 'sanctum')->get("/api/sites/{$site->id}/analytics/export?type=conversions&from=2026-09-10&to=2026-09-19");

        $response->assertOk();
        $this->assertStringContainsString('conversions-2026-09-10_2026-09-19.csv', $response->headers->get('Content-Disposition'));
        $body = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $body))));

        $this->assertSame(['time_utc', 'link', 'event', 'value', 'currency', 'external_id', 'source'], $rows[0]);
        $this->assertSame(['2026-09-12 11:00:00', 'promo', 'purchase', '49.90', 'USD', 'order-1', 'pixel'], $rows[1]);
        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString($click->token, $body);
    }

    public function test_the_export_type_is_validated_and_defaults_to_clicks(): void
    {
        [$user, $site, $link] = $this->scene();
        $this->clickAt($link, '2026-09-12 10:00:00');

        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/analytics/export?type=everything")
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $body = $this->actingAs($user, 'sanctum')->get("/api/sites/{$site->id}/analytics/export?from=2026-09-10&to=2026-09-19")->streamedContent();
        $this->assertStringContainsString('time_utc,link,referrer', $body);
    }
}
