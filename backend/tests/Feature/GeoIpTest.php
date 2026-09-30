<?php

namespace Tests\Feature;

use App\Support\GeoIp;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Tests\Support\MmdbFixture;
use Tests\TestCase;

class GeoIpTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'geo');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function geo(array $networks = ['203.0.113.0/24' => 'UA', '198.51.100.0/24' => 'DE', '2001:db8::/32' => 'GB'], int $ipVersion = 6): GeoIp
    {
        file_put_contents($this->file, MmdbFixture::build($networks, 'DBIP-Country-Lite', $ipVersion));

        return new GeoIp($this->file);
    }

    public function test_it_names_the_country_of_an_ipv4_or_ipv6_address(): void
    {
        $geo = $this->geo();

        $this->assertSame('UA', $geo->countryOf('203.0.113.77'));
        $this->assertSame('DE', $geo->countryOf('198.51.100.1'));
        $this->assertSame('GB', $geo->countryOf('2001:db8:abcd::1'));
        $this->assertTrue($geo->available());
    }

    public function test_an_address_the_database_does_not_cover_is_unknown_not_an_error(): void
    {
        $geo = $this->geo();

        $this->assertNull($geo->countryOf('8.8.8.8'));
        $this->assertNull($geo->countryOf('10.0.0.1'), 'a private address');
        $this->assertNull($geo->countryOf('127.0.0.1'));
        $this->assertNull($geo->countryOf('::1'));
    }

    public function test_input_that_is_not_an_address_is_unknown_not_an_error(): void
    {
        $geo = $this->geo();

        foreach ([null, '', 'not-an-ip', '999.1.1.1', '203.0.113', "203.0.113.1\n", '203.0.113.1/24'] as $input) {
            $this->assertNull($geo->countryOf($input), var_export($input, true));
        }
    }

    public function test_an_ipv6_address_against_an_ipv4_only_database_is_unknown_not_an_error(): void
    {
        $geo = $this->geo(['203.0.113.0/24' => 'UA'], ipVersion: 4);

        $this->assertSame('UA', $geo->countryOf('203.0.113.5'));
        $this->assertNull($geo->countryOf('2001:db8::1'));
    }

    public function test_a_code_that_is_not_a_country_is_not_reported_as_one(): void
    {
        $geo = $this->geo([
            '203.0.113.0/24' => 'ua',
            '198.51.100.0/24' => 'ZZ',
            '192.0.2.0/24' => 'XX',
            '192.88.99.0/24' => 'Ukraine',
            '10.1.0.0/16' => '',
            '10.2.0.0/16' => 'U1',
        ]);

        foreach (['203.0.113.1', '198.51.100.1', '192.0.2.1', '192.88.99.1', '10.1.0.1', '10.2.0.1'] as $ip) {
            $this->assertNull($geo->countryOf($ip), $ip);
        }
    }

    public function test_a_record_with_no_country_in_it_is_unknown(): void
    {
        $geo = $this->geo(['203.0.113.0/24' => ['continent' => ['code' => 'EU']], '198.51.100.0/24' => ['country' => ['names' => ['en' => 'Germany']]]]);

        $this->assertNull($geo->countryOf('203.0.113.1'));
        $this->assertNull($geo->countryOf('198.51.100.1'));
    }

    public function test_without_a_database_everything_is_unknown_and_nothing_throws(): void
    {
        $geo = new GeoIp('/no/such/dir/country.mmdb');

        $this->assertFalse($geo->available());
        $this->assertNull($geo->countryOf('203.0.113.77'));
    }

    public function test_a_corrupt_database_is_unknown_and_reported_once_not_per_click(): void
    {
        file_put_contents($this->file, str_repeat('this is not a database', 500));
        $reported = 0;
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported) {
            $reported++;

            return false;
        });

        // A fresh instance per "request", as in production.
        foreach (range(1, 5) as $ignored) {
            $this->assertNull((new GeoIp($this->file))->countryOf('203.0.113.77'));
        }

        $this->assertSame(1, $reported);
    }

    public function test_an_empty_file_is_treated_as_unusable_rather_than_fatal(): void
    {
        file_put_contents($this->file, '');

        $geo = new GeoIp($this->file);

        $this->assertFalse($geo->available());
        $this->assertNull($geo->countryOf('203.0.113.1'));
    }

    public function test_it_reads_the_configured_path_by_default(): void
    {
        file_put_contents($this->file, MmdbFixture::build(['203.0.113.0/24' => 'UA']));
        config(['features.geoip.database' => $this->file]);

        $this->assertSame('UA', (new GeoIp)->countryOf('203.0.113.1'));
        $this->assertSame($this->file, (new GeoIp)->path());
    }

    public function test_the_container_hands_out_one_reader_per_request(): void
    {
        $this->assertSame($this->app->make(GeoIp::class), $this->app->make(GeoIp::class));
    }
}
