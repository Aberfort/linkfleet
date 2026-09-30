<?php

namespace Tests\Unit;

use App\Support\DohResolver;
use App\Support\OutboundUrlGuard;
use App\Support\UnsafeOutboundUrl;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OutboundUrlGuardTest extends TestCase
{
    /** A guard whose DNS is a fixed table: host => [type => [addresses]]. */
    private function guardWith(array $dns = []): OutboundUrlGuard
    {
        Cache::flush();

        $resolver = new class($dns) extends DohResolver
        {
            public function __construct(private array $dns) {}

            public function lookup(string $name, string $type): array
            {
                return $this->dns[$name][$type] ?? [];
            }
        };

        return new OutboundUrlGuard($resolver);
    }

    public static function publicAddresses(): array
    {
        return [
            'google dns' => ['8.8.8.8'],
            'cloudflare' => ['1.1.1.1'],
            'documentation-adjacent public' => ['93.184.216.34'],
            'just past the 172.16/12 block' => ['172.32.0.1'],
            'just past the 100.64/10 block' => ['100.128.0.1'],
            'public ipv6' => ['2606:4700:4700::1111'],
            'public address mapped into ipv6' => ['::ffff:8.8.8.8'],
        ];
    }

    public static function internalAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'loopback, not .1' => ['127.5.5.5'],
            'this network' => ['0.0.0.0'],
            '10/8' => ['10.1.2.3'],
            '172.16/12 start' => ['172.16.0.1'],
            '172.16/12 end' => ['172.31.255.255'],
            '192.168/16' => ['192.168.1.1'],
            'cloud metadata service' => ['169.254.169.254'],
            'carrier-grade NAT start' => ['100.64.0.1'],
            'carrier-grade NAT end' => ['100.127.255.255'],
            'broadcast' => ['255.255.255.255'],
            'multicast' => ['224.0.0.1'],
            'benchmarking' => ['198.18.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv6 link-local' => ['fe80::1'],
            'ipv6 unique-local (what a private network uses)' => ['fd12:3456:789a::1'],
            'loopback mapped into ipv6' => ['::ffff:127.0.0.1'],
            'metadata service mapped into ipv6' => ['::ffff:169.254.169.254'],
            'nat64 of 127.0.0.1' => ['64:ff9b::7f00:1'],
            '6to4 of 127.0.0.1' => ['2002:7f00:1::1'],
            'documentation range' => ['2001:db8::1'],
            'not an address at all' => ['not-an-ip'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_are_allowed(string $ip): void
    {
        $this->assertTrue(OutboundUrlGuard::isPublicIp($ip));
    }

    #[DataProvider('internalAddresses')]
    public function test_internal_addresses_are_refused(string $ip): void
    {
        $this->assertFalse(OutboundUrlGuard::isPublicIp($ip));
    }

    public function test_a_normal_https_url_resolves_and_is_pinned_to_its_address(): void
    {
        $target = $this->guardWith(['hooks.example.com' => ['A' => ['203.0.114.7']]])
            ->resolve('https://hooks.example.com/in/abc?x=1#frag');

        $this->assertSame('https://hooks.example.com:443/in/abc?x=1', $target->url);
        $this->assertSame('203.0.114.7', $target->ip);
        $this->assertSame('hooks.example.com:443:203.0.114.7', $target->curlResolve());
    }

    public function test_an_ipv6_only_host_is_pinned_in_brackets(): void
    {
        $target = $this->guardWith(['v6.example.com' => ['AAAA' => ['2606:4700:4700::1111']]])
            ->resolve('https://v6.example.com:8443/hook');

        $this->assertSame('v6.example.com:8443:[2606:4700:4700::1111]', $target->curlResolve());
    }

    public function test_a_public_ip_literal_is_accepted(): void
    {
        $target = $this->guardWith()->resolve('https://203.0.114.7:9000/x');

        $this->assertSame('203.0.114.7', $target->ip);
    }

    public static function unsafeUrls(): array
    {
        return [
            'plain http' => ['http://hooks.example.com/x'],
            'no scheme' => ['hooks.example.com/x'],
            'ftp' => ['ftp://hooks.example.com/x'],
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://hooks.example.com/'],
            'javascript' => ['javascript:alert(1)'],
            'credentials in the url' => ['https://user:pass@hooks.example.com/x'],
            'a backslash, which parsers disagree about' => ['https://hooks.example.com\\@127.0.0.1/'],
            'whitespace' => ['https://hooks.example.com/a b'],
            'a newline' => ["https://hooks.example.com/x\nHost: evil"],
            'empty' => [''],
            'localhost' => ['https://localhost/x'],
            'a subdomain of localhost' => ['https://app.localhost/x'],
            'a bare hostname' => ['https://intranet/x'],
            'railway private network' => ['https://mysql.railway.internal:3306/'],
            'mdns .local' => ['https://printer.local/'],
            'loopback literal' => ['https://127.0.0.1/x'],
            'metadata literal' => ['https://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback literal' => ['https://[::1]/x'],
            'ipv4 in decimal' => ['https://2130706433/x'],
            'ipv4 shorthand' => ['https://127.1/x'],
            'octal-looking' => ['https://0177.0.0.1/x'],
            'hex-looking' => ['https://0x7f.0.0.1/x'],
            'port zero' => ['https://hooks.example.com:0/x'],
            'unicode host' => ['https://пример.example.com/x'],
            'underscore host' => ['https://bad_host.example.com/x'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_are_refused_before_any_request(string $url): void
    {
        // Every name here would resolve to a public address, so a refusal
        // can only come from the URL itself.
        $guard = $this->guardWith([
            'hooks.example.com' => ['A' => ['203.0.114.7']],
            'app.localhost' => ['A' => ['203.0.114.7']],
            'intranet' => ['A' => ['203.0.114.7']],
            'mysql.railway.internal' => ['A' => ['203.0.114.7']],
            'printer.local' => ['A' => ['203.0.114.7']],
            // A resolver could answer for these; curl would not go there but
            // to the address they spell, so they must be refused on sight.
            '127.1' => ['A' => ['203.0.114.7']],
            '2130706433' => ['A' => ['203.0.114.7']],
            '0177.0.0.1' => ['A' => ['203.0.114.7']],
            '0x7f.0.0.1' => ['A' => ['203.0.114.7']],
        ]);

        $this->expectException(UnsafeOutboundUrl::class);

        $guard->resolve($url);
    }

    public function test_a_name_that_resolves_to_a_private_address_is_refused(): void
    {
        $guard = $this->guardWith(['sneaky.example.com' => ['A' => ['10.0.0.5']]]);

        $this->expectException(UnsafeOutboundUrl::class);
        $this->expectExceptionMessage('внутрішню мережу');

        $guard->resolve('https://sneaky.example.com/hook');
    }

    public function test_one_private_answer_among_public_ones_condemns_the_name(): void
    {
        // Rebinding often works by mixing a harmless answer with the target.
        $guard = $this->guardWith(['mixed.example.com' => ['A' => ['203.0.114.7', '169.254.169.254']]]);

        $this->expectException(UnsafeOutboundUrl::class);

        $guard->resolve('https://mixed.example.com/hook');
    }

    public function test_a_private_ipv6_answer_condemns_the_name_even_with_a_public_ipv4(): void
    {
        $guard = $this->guardWith(['dual.example.com' => ['A' => ['203.0.114.7'], 'AAAA' => ['fd00::1']]]);

        $this->expectException(UnsafeOutboundUrl::class);

        $guard->resolve('https://dual.example.com/hook');
    }

    public function test_a_name_that_does_not_resolve_is_refused_with_a_useful_message(): void
    {
        $guard = $this->guardWith();

        $this->expectException(UnsafeOutboundUrl::class);
        $this->expectExceptionMessage('Не вдалося визначити адресу');

        $guard->resolve('https://nowhere.example.com/hook');
    }

    public function test_private_targets_can_be_allowed_for_self_hosting(): void
    {
        config(['features.webhooks.allow_private_targets' => true]);
        $guard = $this->guardWith(['n8n.lan' => ['A' => ['192.168.1.20']]]);

        $this->assertSame('192.168.1.20', $guard->resolve('http://n8n.lan:5678/webhook/x')->ip);
        $this->assertSame('127.0.0.1', $guard->resolve('http://127.0.0.1:9000/x')->ip);
    }

    public function test_allowing_private_targets_does_not_relax_the_parsing_rules(): void
    {
        config(['features.webhooks.allow_private_targets' => true]);

        $this->expectException(UnsafeOutboundUrl::class);

        $this->guardWith()->resolve('http://127.1/x');
    }

    public function test_dns_answers_are_cached_briefly(): void
    {
        $calls = 0;
        $resolver = new class($calls) extends DohResolver
        {
            public function __construct(public int &$calls) {}

            public function lookup(string $name, string $type): array
            {
                $this->calls++;

                return $type === 'A' ? ['203.0.114.7'] : [];
            }
        };
        Cache::flush();
        $guard = new OutboundUrlGuard($resolver);

        $guard->resolve('https://hooks.example.com/a');
        $guard->resolve('https://hooks.example.com/b');

        // A and AAAA once each, not once per delivery.
        $this->assertSame(2, $calls);
    }
}
