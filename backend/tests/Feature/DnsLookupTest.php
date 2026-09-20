<?php

namespace Tests\Feature;

use App\Support\DnsTxtLookup;
use App\Support\DohResolver;
use App\Support\DomainProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DnsLookupTest extends TestCase
{
    private const DOH = 'https://doh.test/dns-query';

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.doh_url' => self::DOH]);
    }

    /** A DoH JSON reply with the given answers (type => data pairs). */
    private function answer(array $records, int $status = 0): array
    {
        return [
            'Status' => $status,
            'Answer' => array_map(fn (array $r) => ['name' => 'x', 'type' => $r[0], 'TTL' => 60, 'data' => $r[1]], $records),
        ];
    }

    public function test_it_reads_txt_values_and_strips_the_zone_file_quoting(): void
    {
        Http::fake([self::DOH.'*' => Http::response($this->answer([
            [16, '"plain-token"'],
            [16, '"chunk one, " "chunk two"'],
        ]))]);

        $values = app(DnsTxtLookup::class)->txtValues('_linkfleet.go.example.com');

        $this->assertSame(['plain-token', 'chunk one, chunk two'], $values);
        Http::assertSent(fn ($request) => $request['name'] === '_linkfleet.go.example.com' && $request['type'] === 'TXT');
    }

    public function test_a_name_that_does_not_exist_yields_nothing(): void
    {
        Http::fake([self::DOH.'*' => Http::response(['Status' => 3])]); // NXDOMAIN

        $this->assertSame([], app(DnsTxtLookup::class)->txtValues('nope.example.com'));
    }

    public function test_a_lookup_that_times_out_yields_nothing_instead_of_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->assertSame([], app(DohResolver::class)->lookup('slow.example.com', 'TXT'));
    }

    public function test_a_failing_resolver_yields_nothing(): void
    {
        Http::fake([self::DOH.'*' => Http::response('boom', 500)]);

        $this->assertSame([], app(DohResolver::class)->lookup('go.example.com', 'A'));
    }

    public function test_only_the_requested_record_type_is_kept_from_a_cname_chain(): void
    {
        Http::fake([self::DOH.'*' => Http::response($this->answer([
            [5, 'edge.example.net.'],
            [1, '203.0.113.7'],
        ]))]);

        $this->assertSame(['203.0.113.7'], app(DohResolver::class)->lookup('go.example.com', 'A'));
    }

    public function test_a_cname_to_the_target_counts_as_pointing_at_it(): void
    {
        Http::fake([self::DOH.'*' => Http::response($this->answer([[5, 'Edge.Example.NET.']]))]);

        $this->assertTrue(app(DomainProbe::class)->pointsAt('go.example.com', 'edge.example.net'));
    }

    public function test_a_cname_to_somewhere_else_does_not(): void
    {
        Http::fake(function ($request) {
            return match ($request['type']) {
                'CNAME' => Http::response($this->answer([[5, 'other.example.org.']])),
                default => Http::response($this->answer([])),
            };
        });

        $this->assertFalse(app(DomainProbe::class)->pointsAt('go.example.com', 'edge.example.net'));
    }

    public function test_shared_addresses_count_for_an_apex_domain_without_a_cname(): void
    {
        Http::fake(function ($request) {
            if ($request['type'] === 'CNAME') {
                return Http::response($this->answer([]));
            }

            return Http::response($this->answer([[1, '203.0.113.7']]));
        });

        $this->assertTrue(app(DomainProbe::class)->pointsAt('example.com', 'edge.example.net'));
    }

    public function test_an_unresolvable_host_never_matches_an_unresolvable_target(): void
    {
        // Two empty address lists must not read as "same addresses".
        Http::fake([self::DOH.'*' => Http::response($this->answer([]))]);

        $this->assertFalse(app(DomainProbe::class)->pointsAt('go.example.com', 'edge.example.net'));
    }

    public function test_https_is_ready_only_when_the_host_answers_its_health_route(): void
    {
        Http::fake(['https://ok.example.com/up' => Http::response('', 200), 'https://bad.example.com/up' => Http::response('', 502)]);

        $probe = app(DomainProbe::class);

        $this->assertTrue($probe->servesOverHttps('ok.example.com'));
        $this->assertFalse($probe->servesOverHttps('bad.example.com'));
    }

    public function test_a_missing_certificate_reads_as_not_ready(): void
    {
        Http::fake(fn () => throw new ConnectionException('SSL certificate problem'));

        $this->assertFalse(app(DomainProbe::class)->servesOverHttps('go.example.com'));
    }
}
