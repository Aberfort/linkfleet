<?php

namespace App\Support;

/**
 * A URL that has been checked, plus the exact address it was checked
 * against. Callers must connect to $ip and request $url - never the URL the
 * user typed - or the check proves nothing.
 */
final class ResolvedTarget
{
    public function __construct(
        /** Rebuilt from the parts that were validated, not the original string. */
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
    ) {}

    /** The value for CURLOPT_RESOLVE: pin $host:$port to the vetted address. */
    public function curlResolve(): string
    {
        $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}
