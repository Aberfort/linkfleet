<?php

namespace App\Console\Commands;

use App\Support\GeoIp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use Throwable;

class GeoIpUpdate extends Command
{
    protected $signature = 'geoip:update
                            {--if-stale : Do nothing unless the file is missing or over 35 days old}
                            {--url= : Download this .mmdb or .mmdb.gz instead of the default}';

    protected $description = 'Download the country database used to place clicks on a map';

    /** DB-IP publishes monthly; a bit over a month leaves room for it to be late. */
    private const STALE_AFTER_DAYS = 35;

    public function handle(GeoIp $geoIp): int
    {
        $path = $geoIp->path();

        if ($this->option('if-stale') && is_file($path) && filemtime($path) > now()->subDays(self::STALE_AFTER_DAYS)->timestamp) {
            $this->components->info('The country database is recent enough; nothing to do.');

            return self::SUCCESS;
        }

        foreach ($this->sources() as $url) {
            $this->line("Trying {$url}");

            try {
                $file = $this->fetch($url);
                $this->verify($file);
            } catch (Throwable $e) {
                $this->components->warn($e->getMessage());
                @unlink($path.'.download');

                continue;
            }

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            // Moved into place whole: a lookup that lands mid-update sees the
            // old file or the new one, never half of either.
            rename($file, $path);

            $reader = new Reader($path);
            $this->components->info(sprintf(
                '%s installed (%s, %s).',
                basename($path),
                $reader->metadata()->databaseType,
                number_format(filesize($path) / 1024 / 1024, 1).' MB'
            ));

            return self::SUCCESS;
        }

        $this->components->error('Could not install a country database. Clicks are still recorded, just without a country.');

        return self::FAILURE;
    }

    /**
     * The default is DB-IP's free country file (no account or key needed;
     * CC BY 4.0, which asks for the attribution the dashboard shows). The
     * new month's file appears at the start of the month, so the previous
     * one is the fallback.
     *
     * @return array<int, string>
     */
    private function sources(): array
    {
        if ($url = $this->option('url') ?: config('features.geoip.download_url')) {
            return [$url];
        }

        return [
            'https://download.db-ip.com/free/dbip-country-lite-'.now()->format('Y-m').'.mmdb.gz',
            'https://download.db-ip.com/free/dbip-country-lite-'.now()->subMonthNoOverflow()->format('Y-m').'.mmdb.gz',
        ];
    }

    /** @return string path of the downloaded, unpacked file */
    private function fetch(string $url): string
    {
        $response = Http::timeout(120)->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException("{$url} answered {$response->status()}.");
        }

        $body = $response->body();

        if (str_ends_with(parse_url($url, PHP_URL_PATH) ?? '', '.gz')) {
            $body = @gzdecode($body);

            if ($body === false) {
                throw new \RuntimeException("{$url} is not a valid gzip file.");
            }
        }

        $target = app(GeoIp::class)->path().'.download';

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        file_put_contents($target, $body);

        return $target;
    }

    /**
     * A file that does not open, is not a country database, or answers
     * nothing for well-known addresses must never replace one that works.
     */
    private function verify(string $file): void
    {
        try {
            $reader = new Reader($file);
        } catch (Throwable) {
            throw new \RuntimeException('The download is not a valid MaxMind DB file.');
        }

        if (! str_contains(strtolower($reader->metadata()->databaseType), 'country')) {
            throw new \RuntimeException("Expected a country database, got \"{$reader->metadata()->databaseType}\".");
        }

        $known = array_filter(['8.8.8.8', '1.1.1.1', '9.9.9.9'], fn ($ip) => ($reader->get($ip)['country']['iso_code'] ?? null) !== null);

        if ($known === []) {
            throw new \RuntimeException('The database answered nothing for well-known addresses; refusing to install it.');
        }
    }
}
