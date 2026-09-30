<?php

namespace Tests\Feature;

use App\Support\GeoIp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use Tests\Support\MmdbFixture;
use Tests\TestCase;

class GeoIpUpdateTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/geoupdate-'.uniqid();
        $this->path = $this->dir.'/country.mmdb';
        config(['features.geoip.database' => $this->path, 'features.geoip.download_url' => null]);
        $this->travelTo('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** A country database that knows the well-known addresses, as a real one does. */
    private function database(string $type = 'DBIP-Country-Lite', array $extra = []): string
    {
        return MmdbFixture::build(['8.8.8.0/24' => 'US', '1.1.1.0/24' => 'AU', ...$extra], $type);
    }

    private function gz(string $bytes): string
    {
        return gzencode($bytes);
    }

    public function test_it_downloads_unpacks_and_installs_this_months_file(): void
    {
        Http::fake(['download.db-ip.com/free/dbip-country-lite-2026-10.mmdb.gz' => Http::response($this->gz($this->database()))]);

        $this->artisan('geoip:update')->assertSuccessful();

        $this->assertFileExists($this->path);
        $this->assertSame('US', (new GeoIp($this->path))->countryOf('8.8.8.8'));
        $this->assertFileDoesNotExist($this->path.'.download', 'no half-finished file left behind');
    }

    public function test_when_the_new_month_is_not_out_yet_it_falls_back_to_the_last_one(): void
    {
        Http::fake([
            'download.db-ip.com/free/dbip-country-lite-2026-10.mmdb.gz' => Http::response('', 404),
            'download.db-ip.com/free/dbip-country-lite-2026-09.mmdb.gz' => Http::response($this->gz($this->database())),
        ]);

        $this->artisan('geoip:update')->assertSuccessful();

        $this->assertSame('US', (new GeoIp($this->path))->countryOf('8.8.8.8'));
    }

    public function test_the_fallback_survives_a_month_boundary(): void
    {
        $this->travelTo('2026-03-31 12:00:00');
        Http::fake([
            'download.db-ip.com/free/dbip-country-lite-2026-03.mmdb.gz' => Http::response('', 404),
            'download.db-ip.com/free/dbip-country-lite-2026-02.mmdb.gz' => Http::response($this->gz($this->database())),
        ]);

        // subMonth() on the 31st would land on "February 31st" = March.
        $this->artisan('geoip:update')->assertSuccessful();
    }

    public function test_an_uncompressed_file_and_a_custom_source_work(): void
    {
        Http::fake(['mirror.example.com/country.mmdb' => Http::response($this->database('GeoLite2-Country'))]);

        $this->artisan('geoip:update', ['--url' => 'https://mirror.example.com/country.mmdb'])->assertSuccessful();

        $this->assertSame('US', (new GeoIp($this->path))->countryOf('8.8.8.8'));
        Http::assertSentCount(1);
    }

    public function test_the_configured_source_replaces_the_default(): void
    {
        config(['features.geoip.download_url' => 'https://mirror.example.com/c.mmdb.gz']);
        Http::fake(['mirror.example.com/*' => Http::response($this->gz($this->database()))]);

        $this->artisan('geoip:update')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'mirror.example.com'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'db-ip.com'));
    }

    public function test_a_recent_file_is_left_alone_when_asked_only_to_refresh_stale_ones(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->path, $this->database());
        touch($this->path, now()->subDays(10)->timestamp);
        Http::fake();

        $this->artisan('geoip:update', ['--if-stale' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_an_old_file_is_refreshed(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->path, $this->database(extra: ['203.0.113.0/24' => 'UA']));
        touch($this->path, now()->subDays(40)->timestamp);
        Http::fake(['*' => Http::response($this->gz($this->database(extra: ['203.0.113.0/24' => 'PL'])))]);

        $this->artisan('geoip:update', ['--if-stale' => true])->assertSuccessful();

        $this->assertSame('PL', (new GeoIp($this->path))->countryOf('203.0.113.1'));
    }

    public function test_without_the_stale_flag_it_always_downloads(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->path, $this->database());
        touch($this->path, now()->subDay()->timestamp);
        Http::fake(['*' => Http::response($this->gz($this->database()))]);

        $this->artisan('geoip:update')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_a_download_that_is_not_a_database_never_replaces_a_working_one(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->path, $this->database(extra: ['203.0.113.0/24' => 'UA']));
        Http::fake(['*' => Http::response($this->gz('<html>maintenance page</html>'))]);

        $this->artisan('geoip:update')->assertFailed();

        $this->assertSame('UA', (new GeoIp($this->path))->countryOf('203.0.113.1'), 'the old database still works');
        $this->assertFileDoesNotExist($this->path.'.download');
    }

    public function test_corrupt_gzip_is_refused(): void
    {
        Http::fake(['*' => Http::response('this is not gzip at all')]);

        $this->artisan('geoip:update')->assertFailed();

        $this->assertFileDoesNotExist($this->path);
    }

    public function test_a_database_of_something_other_than_countries_is_refused(): void
    {
        Http::fake(['*' => Http::response($this->gz($this->database('GeoLite2-ASN')))]);

        $this->artisan('geoip:update')->assertFailed();

        $this->assertFileDoesNotExist($this->path);
    }

    public function test_a_database_that_knows_no_well_known_address_is_refused(): void
    {
        // Valid, and named right, but empty of anything useful - a truncated build, say.
        Http::fake(['*' => Http::response($this->gz(MmdbFixture::build(['203.0.113.0/24' => 'UA'])))]);

        $this->artisan('geoip:update')->assertFailed();

        $this->assertFileDoesNotExist($this->path);
    }

    public function test_when_every_source_fails_it_says_so_and_changes_nothing(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->path, $this->database(extra: ['203.0.113.0/24' => 'UA']));
        Http::fake(['*' => Http::response('', 500)]);

        $this->artisan('geoip:update')->assertFailed();

        $this->assertSame('UA', (new GeoIp($this->path))->countryOf('203.0.113.1'));
    }

    public function test_a_connection_error_is_a_failure_not_a_crash(): void
    {
        Http::fake(fn () => throw new ConnectionException('could not resolve host'));

        $this->artisan('geoip:update')->assertFailed();
    }

    public function test_the_installed_file_is_a_complete_database_the_reader_accepts(): void
    {
        Http::fake(['*' => Http::response($this->gz($this->database()))]);

        $this->artisan('geoip:update')->assertSuccessful();

        $reader = new Reader($this->path);
        $this->assertSame('DBIP-Country-Lite', $reader->metadata()->databaseType);
    }
}
