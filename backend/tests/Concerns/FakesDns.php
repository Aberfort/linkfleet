<?php

namespace Tests\Concerns;

use App\Support\DohResolver;
use Illuminate\Support\Facades\Cache;

trait FakesDns
{
    /**
     * Replaces DNS-over-HTTPS with a fixed table, so the real
     * OutboundUrlGuard runs against names that resolve to what the test
     * says and nothing ever leaves the machine.
     *
     * @param  array<string, array<string, array<int, string>>>  $table  host => [type => [addresses]]
     */
    protected function fakeDns(array $table = []): void
    {
        Cache::flush();

        $this->app->bind(DohResolver::class, fn () => new class($table) extends DohResolver
        {
            public function __construct(private array $table) {}

            public function lookup(string $name, string $type): array
            {
                return $this->table[$name][$type] ?? [];
            }
        });
    }

    /** The hostnames most tests deliver to. */
    protected function fakePublicReceivers(): void
    {
        $this->fakeDns([
            'hooks.example.com' => ['A' => ['203.0.114.7']],
            'other.example.com' => ['A' => ['203.0.114.8']],
            'sneaky.example.com' => ['A' => ['10.0.0.5']],
        ]);
    }
}
