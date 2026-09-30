<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Which country an address is in, answered from a database file on this
 * machine. Nothing is sent anywhere: the lookup happens in-process while the
 * click is recorded, and only the two-letter result is kept.
 *
 * The database is optional. Without one (not downloaded yet, unreadable,
 * corrupt) every answer is null and clicks are recorded exactly as before -
 * a redirect must never depend on it.
 */
class GeoIp
{
    /** Codes databases use for "we do not know" - not countries. */
    private const NOT_A_COUNTRY = ['ZZ', 'XX', 'T1'];

    private ?Reader $reader = null;

    private bool $opened = false;

    public function __construct(private readonly ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? (string) config('features.geoip.database');
    }

    public function available(): bool
    {
        return $this->reader() !== null;
    }

    /** "UA", or null when unknown, private, malformed or there is no database. */
    public function countryOf(?string $ip): ?string
    {
        $reader = $ip ? $this->reader() : null;

        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (Throwable) {
            // Not an address, or an IPv6 one in an IPv4-only file.
            return null;
        }

        $code = is_array($record) ? ($record['country']['iso_code'] ?? null) : null;

        return is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1 && ! in_array($code, self::NOT_A_COUNTRY, true)
            ? $code
            : null;
    }

    private function reader(): ?Reader
    {
        if ($this->opened) {
            return $this->reader;
        }

        $this->opened = true;

        if (! is_file($this->path())) {
            return null;
        }

        try {
            return $this->reader = new Reader($this->path());
        } catch (Throwable $e) {
            // Said once an hour, not once per click: a broken file would
            // otherwise fill the log at the speed of the traffic.
            if (Cache::add('geoip-unreadable-logged', true, 3600)) {
                report($e);
            }

            return null;
        }
    }
}
