<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Answers "is this host actually wired up to us yet?" in two independent
 * steps - DNS and TLS - so the UI can say which one is still missing.
 * Like DnsTxtLookup it is a seam: tests bind a fake instead of touching the
 * network.
 */
class DomainProbe
{
    public function __construct(private DohResolver $resolver) {}

    /**
     * Whether $host's DNS leads to $target, either through a CNAME or by
     * resolving to at least one of the same addresses (an apex domain can't
     * carry a CNAME, so it gets an A record instead).
     */
    public function pointsAt(string $host, string $target): bool
    {
        $target = $this->normalise($target);

        foreach ($this->resolver->lookup($host, 'CNAME') as $cname) {
            if ($this->normalise($cname) === $target) {
                return true;
            }
        }

        $hostIps = $this->resolver->lookup($host, 'A');

        return $hostIps !== [] && array_intersect($hostIps, $this->resolver->lookup($target, 'A')) !== [];
    }

    /**
     * Whether https://$host/up answers with a valid certificate. Certificate
     * verification stays on: a self-signed or missing certificate is exactly
     * the "not ready" state this is meant to report.
     */
    public function servesOverHttps(string $host): bool
    {
        try {
            return Http::timeout(5)->get("https://{$host}/up")->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function normalise(string $name): string
    {
        return rtrim(strtolower($name), '.');
    }
}
