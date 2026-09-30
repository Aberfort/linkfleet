<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Decides whether LinkFleet may make a request to a URL a user supplied.
 *
 * A webhook makes the server call an address chosen by a customer, which is
 * exactly the shape of a server-side request forgery: point it at the cloud
 * metadata service, at `mysql.railway.internal`, at localhost, and read what
 * comes back in the delivery log. So nothing is called until:
 *
 * - the URL has been taken apart and rebuilt from parts that passed strict
 *   checks (a hostname that only PHP understands and curl reads differently
 *   is how these guards are usually beaten);
 * - the hostname has been resolved *here*, and every address it returns is a
 *   public one - a single internal answer rejects the whole name;
 * - the caller connects to that exact address (see ResolvedTarget), so the
 *   name cannot resolve somewhere else between the check and the request.
 *
 * Self-hosters who genuinely want to notify an internal service opt in with
 * WEBHOOKS_ALLOW_PRIVATE_TARGETS; that lifts the address and https rules.
 */
class OutboundUrlGuard
{
    /** Addresses that are not the public internet. */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const BLOCKED_V6 = [
        '::/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa'];

    public function __construct(private DohResolver $resolver) {}

    /**
     * @throws UnsafeOutboundUrl
     */
    public function resolve(string $url): ResolvedTarget
    {
        $allowPrivate = (bool) config('features.webhooks.allow_private_targets');

        // Anything a lenient parser might read two ways is rejected outright
        // rather than interpreted.
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new UnsafeOutboundUrl('Адреса містить недопустимі символи.');
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeOutboundUrl('Адреса некоректна. Вкажіть повний URL, наприклад https://example.com/hook.');
        }

        $scheme = strtolower($parts['scheme']);
        $allowed = $allowPrivate ? ['http', 'https'] : ['https'];

        if (! in_array($scheme, $allowed, true)) {
            throw new UnsafeOutboundUrl($allowPrivate ? 'Дозволені лише http та https.' : 'Дозволені лише https-адреси.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeOutboundUrl('Адреса не повинна містити логін чи пароль.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if ($port < 1 || $port > 65535) {
            throw new UnsafeOutboundUrl('Некоректний порт.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (! $isIp) {
            $this->assertPlainHostname($host, $allowPrivate);
        }

        $ips = $isIp ? [$host] : $this->addressesOf($host);

        if ($ips === []) {
            throw new UnsafeOutboundUrl("Не вдалося визначити адресу «{$host}». Перевірте, що домен існує.");
        }

        if (! $allowPrivate) {
            foreach ($ips as $ip) {
                if (! self::isPublicIp($ip)) {
                    throw new UnsafeOutboundUrl('Ця адреса веде у внутрішню мережу, тому її заборонено.');
                }
            }
        }

        $ip = collect($ips)->first(fn (string $ip) => ! str_contains($ip, ':')) ?? $ips[0];
        $hostForUrl = str_contains($host, ':') ? "[{$host}]" : $host;

        return new ResolvedTarget(
            url: "{$scheme}://{$hostForUrl}:{$port}".($parts['path'] ?? '/').(isset($parts['query']) ? "?{$parts['query']}" : ''),
            host: $host,
            port: $port,
            ip: $ip,
        );
    }

    /** Public means: routable on the internet, not loopback/private/link-local/reserved. */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = inet_pton($ip);

        if (strlen($packed) === 16) {
            // ::ffff:a.b.c.d is just the IPv4 address a.b.c.d in disguise.
            if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
                return self::isPublicIp(inet_ntop(substr($packed, 12)));
            }

            $blocked = self::BLOCKED_V6;
        } else {
            $blocked = self::BLOCKED_V4;
        }

        foreach ($blocked as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = inet_pton($network);

        if (strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        $rest = $bits % 8;

        if ($whole > 0 && substr($packed, 0, $whole) !== substr($networkPacked, 0, $whole)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($networkPacked[$whole]) & $mask);
    }

    private function assertPlainHostname(string $host, bool $allowPrivate): void
    {
        // Letters, digits, hyphens, dots - punycode for anything else. This
        // is what keeps PHP and curl agreeing about what the host is.
        if (preg_match('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)*$/', $host) !== 1 || strlen($host) > 253) {
            throw new UnsafeOutboundUrl('Домен містить недопустимі символи. Для не-латинських доменів використовуйте punycode.');
        }

        // The last label must start with a letter, as every real top-level
        // domain does. curl reads "127.1", "2130706433" and "0x7f.0.0.1" as
        // IPv4 addresses *before* it looks at any pinned resolution, so a
        // name like that would walk straight past the address check.
        $labels = explode('.', $host);

        if (preg_match('/^[a-z]/', end($labels)) !== 1) {
            throw new UnsafeOutboundUrl('Адресу слід вказувати доменним іменем або повною IP-адресою, наприклад 203.0.113.10.');
        }

        if ($allowPrivate) {
            return;
        }

        if ($host === 'localhost' || ! str_contains($host, '.')) {
            throw new UnsafeOutboundUrl('Ця адреса веде у внутрішню мережу, тому її заборонено.');
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new UnsafeOutboundUrl('Ця адреса веде у внутрішню мережу, тому її заборонено.');
            }
        }
    }

    /**
     * Every A and AAAA answer for $host. Cached briefly: a delivery burst
     * should not turn into a burst of DNS queries, and the address is
     * pinned per request anyway.
     *
     * @return array<int, string>
     */
    private function addressesOf(string $host): array
    {
        return Cache::remember("outbound-dns:{$host}", 60, function () use ($host) {
            return [...$this->resolver->lookup($host, 'A'), ...$this->resolver->lookup($host, 'AAAA')];
        }) ?: [];
    }
}
