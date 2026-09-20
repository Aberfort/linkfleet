<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DNS over HTTPS, JSON flavour. Used instead of dns_get_record() /
 * gethostbynamel() because those go through the system resolver, which has
 * no timeout we can bound - and the names looked up here are supplied by
 * customers. A domain whose nameserver never answers would otherwise pin a
 * PHP worker for as long as the resolver cares to wait.
 */
class DohResolver
{
    /** Record type numbers, as they appear in a DoH JSON answer. */
    private const TYPES = ['A' => 1, 'CNAME' => 5, 'TXT' => 16];

    /**
     * The data of every $type record at $name; empty when the name doesn't
     * exist, has none of that type, or the lookup failed or timed out.
     *
     * @param  'A'|'CNAME'|'TXT'  $type
     * @return array<int, string>
     */
    public function lookup(string $name, string $type): array
    {
        try {
            $response = Http::timeout(3)
                ->withHeaders(['Accept' => 'application/dns-json'])
                ->get(config('features.doh_url'), ['name' => $name, 'type' => $type]);
        } catch (Throwable) {
            return [];
        }

        // 0 is NOERROR; anything else (NXDOMAIN, SERVFAIL...) means no usable answer.
        if (! $response->successful() || $response->json('Status') !== 0) {
            return [];
        }

        $data = [];

        foreach ($response->json('Answer', []) as $answer) {
            // A CNAME chain is returned alongside the final A records - keep only what was asked for.
            if (($answer['type'] ?? null) === self::TYPES[$type] && isset($answer['data'])) {
                $data[] = $answer['data'];
            }
        }

        return $data;
    }
}
