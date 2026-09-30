<?php

namespace Tests\Unit;

use MaxMind\Db\Reader;
use PHPUnit\Framework\TestCase;
use Tests\Support\MmdbFixture;

/** The fixture is test infrastructure, so it gets a test: it must be a file the real reader accepts. */
class MmdbFixtureTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'mmdb');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function reader(array $networks, string $type = 'DBIP-Country-Lite'): Reader
    {
        file_put_contents($this->file, MmdbFixture::build($networks, $type));

        return new Reader($this->file);
    }

    public function test_the_real_reader_opens_it_and_reads_its_metadata(): void
    {
        $meta = $this->reader(['203.0.113.0/24' => 'UA'])->metadata();

        $this->assertSame('DBIP-Country-Lite', $meta->databaseType);
        $this->assertSame(6, $meta->ipVersion);
        $this->assertSame(24, $meta->recordSize);
        $this->assertSame(['en'], $meta->languages);
        $this->assertSame(2, $meta->binaryFormatMajorVersion);
        $this->assertGreaterThan(96, $meta->nodeCount, 'the ::/96 path alone is 96 nodes');
    }

    public function test_ipv4_networks_are_found_and_addresses_outside_them_are_not(): void
    {
        $reader = $this->reader(['203.0.113.0/24' => 'UA', '198.51.100.0/24' => 'DE']);

        $this->assertSame('UA', $reader->get('203.0.113.1')['country']['iso_code']);
        $this->assertSame('UA', $reader->get('203.0.113.254')['country']['iso_code']);
        $this->assertSame('DE', $reader->get('198.51.100.77')['country']['iso_code']);
        $this->assertNull($reader->get('203.0.114.1'), 'the next /24 along');
        $this->assertNull($reader->get('8.8.8.8'));
    }

    public function test_prefix_boundaries_are_exact(): void
    {
        $reader = $this->reader(['192.0.2.128/25' => 'PL']);

        $this->assertNull($reader->get('192.0.2.127'));
        $this->assertSame('PL', $reader->get('192.0.2.128')['country']['iso_code']);
        $this->assertSame('PL', $reader->get('192.0.2.255')['country']['iso_code']);
    }

    public function test_a_more_specific_network_can_sit_beside_a_wider_one(): void
    {
        $reader = $this->reader(['10.0.0.0/8' => 'US', '11.0.0.0/8' => 'CA']);

        $this->assertSame('US', $reader->get('10.200.3.4')['country']['iso_code']);
        $this->assertSame('CA', $reader->get('11.0.0.1')['country']['iso_code']);
    }

    public function test_ipv6_networks_work_beside_ipv4_ones(): void
    {
        $reader = $this->reader(['203.0.113.0/24' => 'UA', '2001:db8::/32' => 'GB']);

        $this->assertSame('GB', $reader->get('2001:db8:1234::1')['country']['iso_code']);
        $this->assertNull($reader->get('2001:db9::1'));
        $this->assertSame('UA', $reader->get('203.0.113.9')['country']['iso_code']);
    }

    public function test_a_record_can_be_anything_the_data_format_holds(): void
    {
        $reader = $this->reader(['203.0.113.0/24' => ['registered_country' => ['iso_code' => 'FR'], 'continent' => ['code' => 'EU']]]);

        $record = $reader->get('203.0.113.5');

        $this->assertSame('FR', $record['registered_country']['iso_code']);
        $this->assertArrayNotHasKey('country', $record);
    }

    public function test_networks_sharing_a_record_share_it(): void
    {
        $reader = $this->reader(['203.0.113.0/24' => 'UA', '198.51.100.0/24' => 'UA']);

        $this->assertSame('UA', $reader->get('203.0.113.1')['country']['iso_code']);
        $this->assertSame('UA', $reader->get('198.51.100.1')['country']['iso_code']);
    }
}
