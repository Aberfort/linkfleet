<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30 15:45:00');
    }

    private function clickAt(Link $link, string $when, array $attributes = []): void
    {
        $click = $link->clicks()->create($attributes);
        $click->forceFill(['created_at' => $when])->save();
    }

    /** @return array<int, array<int, string>> the CSV's rows, header first, BOM stripped */
    private function rows(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

        return array_map('str_getcsv', array_filter(explode("\n", $body), fn ($line) => trim($line) !== ''));
    }

    public function test_a_site_export_is_a_csv_of_its_clicks_oldest_first(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create(['name' => 'My Shop']);
        $promo = Link::factory()->for($site)->create(['short_code' => 'promo']);
        $docs = Link::factory()->for($site)->create(['short_code' => 'docs']);
        $this->clickAt($docs, '2026-09-12 11:00:00', ['referrer' => 'google.com', 'browser' => 'Firefox', 'browser_version' => '131.0', 'platform' => 'macOS', 'device_type' => 'desktop']);
        $this->clickAt($promo, '2026-09-11 10:00:00', ['referrer' => 'twitter.com', 'browser' => 'Chrome', 'browser_version' => '129.0', 'platform' => 'Windows', 'device_type' => 'desktop']);

        $response = $this->actingAs($user, 'sanctum')->get("/api/sites/{$site->id}/analytics/export?from=2026-09-10&to=2026-09-19");

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertSame(
            'attachment; filename=linkfleet-my-shop-2026-09-10_2026-09-19.csv',
            $response->headers->get('Content-Disposition')
        );

        $rows = $this->rows($response->streamedContent());
        $this->assertSame(['time_utc', 'link', 'referrer', 'browser', 'browser_version', 'platform', 'device_type'], $rows[0]);
        $this->assertCount(3, $rows);
        // Clicks come out in the order they were recorded (by id), not the order they were backdated.
        $this->assertSame(['2026-09-12 11:00:00', 'docs', 'google.com', 'Firefox', '131.0', 'macOS', 'desktop'], $rows[1]);
        $this->assertSame(['2026-09-11 10:00:00', 'promo', 'twitter.com', 'Chrome', '129.0', 'Windows', 'desktop'], $rows[2]);
    }

    public function test_the_file_opens_correctly_in_excel(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $body = $this->actingAs($user, 'sanctum')->get("/api/sites/{$site->id}/analytics/export")->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'a UTF-8 byte-order mark');
    }

    public function test_only_the_range_is_exported(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $link = Link::factory()->for($site)->create(['short_code' => 'promo']);
        $this->clickAt($link, '2026-09-09 23:59:59', ['referrer' => 'before.example']);
        $this->clickAt($link, '2026-09-10 00:00:00', ['referrer' => 'first.example']);
        $this->clickAt($link, '2026-09-19 23:59:59', ['referrer' => 'last.example']);
        $this->clickAt($link, '2026-09-20 00:00:00', ['referrer' => 'after.example']);

        $body = $this->actingAs($user, 'sanctum')
            ->get("/api/sites/{$site->id}/analytics/export?from=2026-09-10&to=2026-09-19")->streamedContent();

        $this->assertStringContainsString('first.example', $body);
        $this->assertStringContainsString('last.example', $body);
        $this->assertStringNotContainsString('before.example', $body);
        $this->assertStringNotContainsString('after.example', $body);
    }

    public function test_it_holds_nothing_from_other_sites_and_nothing_that_identifies_a_visitor(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $mine = Link::factory()->for($site)->create();
        $theirs = Link::factory()->for(Site::factory()->create())->create();
        $this->clickAt($mine, '2026-09-12 10:00:00', ['ip_hash' => 'SECRETHASH', 'user_agent' => 'SECRET-UA/1.0', 'referrer' => 'mine.example']);
        $this->clickAt($theirs, '2026-09-12 10:00:00', ['referrer' => 'theirs.example']);

        $body = $this->actingAs($user, 'sanctum')
            ->get("/api/sites/{$site->id}/analytics/export?from=2026-09-10&to=2026-09-19")->streamedContent();

        $this->assertStringContainsString('mine.example', $body);
        $this->assertStringNotContainsString('theirs.example', $body);
        $this->assertStringNotContainsString('SECRETHASH', $body);
        $this->assertStringNotContainsString('SECRET-UA', $body);
    }

    public function test_a_value_a_spreadsheet_would_run_as_a_formula_is_defused(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $link = Link::factory()->for($site)->create();
        // Everything in a click comes from a visitor's browser.
        $this->clickAt($link, '2026-09-12 10:00:00', [
            'referrer' => '=HYPERLINK("http://evil.example","click")',
            'browser' => '+cmd|calc',
            'platform' => '-2+3',
            'device_type' => '@SUM(A1)',
            'browser_version' => "\tTAB",
        ]);

        $rows = $this->rows($this->actingAs($user, 'sanctum')
            ->get("/api/sites/{$site->id}/analytics/export?from=2026-09-10&to=2026-09-19")->streamedContent());

        [, , $referrer, $browser, $version, $platform, $device] = $rows[1];
        $this->assertSame('\'=HYPERLINK("http://evil.example","click")', $referrer);
        $this->assertSame("'+cmd|calc", $browser);
        $this->assertSame("'-2+3", $platform);
        $this->assertSame("'@SUM(A1)", $device);
        $this->assertSame("'\tTAB", $version);
    }

    public function test_awkward_characters_survive_the_csv_quoting(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $link = Link::factory()->for($site)->create();
        $this->clickAt($link, '2026-09-12 10:00:00', ['referrer' => 'a,b"c.example', 'browser' => "two\nlines", 'platform' => 'Мак ОС']);

        $rows = $this->rows($this->actingAs($user, 'sanctum')
            ->get("/api/sites/{$site->id}/analytics/export?from=2026-09-10&to=2026-09-19")->streamedContent());

        $this->assertSame('a,b"c.example', $rows[1][2]);
    }

    public function test_a_link_export_covers_that_link_alone(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $one = Link::factory()->for($site)->create(['short_code' => 'one']);
        $two = Link::factory()->for($site)->create(['short_code' => 'two']);
        $this->clickAt($one, '2026-09-12 10:00:00', ['referrer' => 'one.example']);
        $this->clickAt($two, '2026-09-12 10:00:00', ['referrer' => 'two.example']);

        $response = $this->actingAs($user, 'sanctum')->get("/api/links/{$one->id}/analytics/export?from=2026-09-10&to=2026-09-19");

        $response->assertOk();
        $this->assertSame('attachment; filename=linkfleet-one-2026-09-10_2026-09-19.csv', $response->headers->get('Content-Disposition'));
        $body = $response->streamedContent();
        $this->assertStringContainsString('one.example', $body);
        $this->assertStringNotContainsString('two.example', $body);
    }

    public function test_a_site_named_in_unicode_still_gets_a_safe_filename(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create(['name' => 'Мій магазин']);

        $response = $this->actingAs($user, 'sanctum')->get("/api/sites/{$site->id}/analytics/export?from=2026-09-10&to=2026-09-19");

        $this->assertSame('attachment; filename=linkfleet-export-2026-09-10_2026-09-19.csv', $response->headers->get('Content-Disposition'));
    }

    public function test_one_export_is_capped_at_the_configured_number_of_rows(): void
    {
        // Regression: the cap was once a ->limit() that lazyById() discarded.
        config(['features.analytics_export_row_limit' => 5]);
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $link = Link::factory()->for($site)->create();
        // More than one chunk's worth, so paging is really exercised.
        for ($i = 0; $i < 1500; $i++) {
            $this->clickAt($link, '2026-09-12 10:00:00');
        }

        $rows = $this->rows($this->actingAs($user, 'sanctum')
            ->get("/api/sites/{$site->id}/analytics/export?from=2026-09-10&to=2026-09-19")->streamedContent());

        $this->assertCount(6, $rows, 'the header and five clicks');
    }

    public function test_an_export_larger_than_a_page_comes_out_whole_and_in_order(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $link = Link::factory()->for($site)->create();
        for ($i = 0; $i < 2500; $i++) {
            $this->clickAt($link, '2026-09-12 10:00:00', ['referrer' => "r{$i}.example"]);
        }

        $rows = $this->rows($this->actingAs($user, 'sanctum')
            ->get("/api/sites/{$site->id}/analytics/export?from=2026-09-10&to=2026-09-19")->streamedContent());

        $this->assertCount(2501, $rows);
        $this->assertSame('r0.example', $rows[1][2]);
        $this->assertSame('r1999.example', $rows[2000][2]);
        $this->assertSame('r2499.example', $rows[2500][2]);
    }

    public function test_a_stranger_gets_nothing(): void
    {
        $site = Site::factory()->create();
        $link = Link::factory()->for($site)->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')->getJson("/api/sites/{$site->id}/analytics/export")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/links/{$link->id}/analytics/export")->assertForbidden();
    }

    public function test_the_range_is_validated_like_the_dashboard_is(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();

        $this->actingAs($user, 'sanctum')->getJson("/api/sites/{$site->id}/analytics/export?from=2026-09-19&to=2026-09-10")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_a_read_only_api_key_can_export(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->ownedBy($user)->create();
        $key = $user->createToken('reports', ['read'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($key)->get("/api/sites/{$site->id}/analytics/export")->assertOk();
    }
}
