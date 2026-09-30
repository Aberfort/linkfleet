<?php

namespace App\Support;

use App\Models\Click;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns a set of clicks into the numbers on the dashboard. Give it the
 * clicks that belong to a site or a link; it narrows them to a range.
 */
class AnalyticsReport
{
    /** @param  Builder<Click>  $clicks  every click of whatever is being reported on */
    public function __construct(private Builder $clicks) {}

    /**
     * @return array<string, mixed>
     */
    public function build(AnalyticsRange $range, bool $withComparison = false): array
    {
        $inRange = $this->within($range);

        $report = [
            'range' => $range->toArray(),
            'totals' => $this->totals($inRange),
            'timeseries' => $this->timeseries($inRange, $range),
            'referrers' => $this->breakdown($inRange, 'referrer'),
            'browsers' => $this->breakdown($inRange, 'browser'),
            'devices' => $this->breakdown($inRange, 'device_type'),
        ];

        if ($withComparison) {
            $previous = $range->previous();
            $before = $this->within($previous);

            $report['previous'] = [
                'range' => $previous->toArray(),
                'totals' => $this->totals($before),
                'timeseries' => $this->timeseries($before, $previous),
            ];
        }

        return $report;
    }

    /** @return Builder<Click> */
    public function within(AnalyticsRange $range): Builder
    {
        return (clone $this->clicks)
            ->where('clicks.created_at', '>=', $range->start())
            ->where('clicks.created_at', '<', $range->end());
    }

    /**
     * `visitors` counts distinct hashed networks, not people: the address is
     * truncated to a /24 before it is hashed (see ClientIp), so two people
     * on one network count once. That is the price of not keeping IPs, and
     * it is why the dashboard says "approximately".
     *
     * @return array{clicks: int, visitors: int}
     */
    private function totals(Builder $inRange): array
    {
        $row = (clone $inRange)->selectRaw('COUNT(*) as clicks, COUNT(DISTINCT clicks.ip_hash) as visitors')->first();

        return ['clicks' => (int) $row->clicks, 'visitors' => (int) $row->visitors];
    }

    /**
     * Every day in the range, quiet ones as zero, so the chart never has to
     * guess at a missing date.
     *
     * @return Collection<int, array{date: string, clicks: int}>
     */
    private function timeseries(Builder $inRange, AnalyticsRange $range): Collection
    {
        $perDay = (clone $inRange)
            ->selectRaw('DATE(clicks.created_at) as date, COUNT(*) as clicks')
            ->groupBy('date')
            ->pluck('clicks', 'date');

        $days = collect();

        for ($day = $range->from; $day->lte($range->to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $days->push(['date' => $key, 'clicks' => (int) ($perDay[$key] ?? 0)]);
        }

        return $days;
    }

    /** @return Collection<int, object> */
    private function breakdown(Builder $inRange, string $column): Collection
    {
        return (clone $inRange)
            ->selectRaw("COALESCE(clicks.$column, 'Unknown') as label, COUNT(*) as clicks")
            ->groupBy('label')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get();
    }
}
