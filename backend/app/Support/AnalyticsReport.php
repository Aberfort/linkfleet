<?php

namespace App\Support;

use App\Models\Click;
use App\Models\Conversion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns a set of clicks into the numbers on the dashboard. Give it the
 * clicks that belong to a site or a link; it narrows them to a range.
 */
class AnalyticsReport
{
    /**
     * @param  Builder<Click>  $clicks  every click of whatever is being reported on
     * @param  Builder<Conversion>|null  $conversions  every conversion of the same
     */
    public function __construct(private Builder $clicks, private ?Builder $conversions = null) {}

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
            'countries' => $this->breakdown($inRange, 'country'),
        ];

        if ($this->conversions) {
            $report['conversions'] = $this->conversionsFor($range, $report['totals']['clicks'], detailed: true);
        }

        if ($withComparison) {
            $previous = $range->previous();
            $before = $this->within($previous);
            $beforeTotals = $this->totals($before);

            $report['previous'] = [
                'range' => $previous->toArray(),
                'totals' => $beforeTotals,
                'timeseries' => $this->timeseries($before, $previous),
                ...($this->conversions ? ['conversions' => $this->conversionsFor($previous, $beforeTotals['clicks'], detailed: false)] : []),
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
            // A tie must not be left to the database's whim, or the same page
            // would list equals in a different order from one load to the next.
            ->orderBy('label')
            ->limit(10)
            ->get();
    }

    /** @return Builder<Conversion> */
    public function conversionsWithin(AnalyticsRange $range): Builder
    {
        return (clone $this->conversions)
            ->where('conversions.created_at', '>=', $range->start())
            ->where('conversions.created_at', '<', $range->end());
    }

    /**
     * What visitors did after clicking, counted in the range they did it.
     *
     * `rate` is the share of the range's clicks that were followed by a
     * conversion, counting a click once however many orders it led to. A
     * conversion can belong to a click from before the range, so it is
     * capped at 100% rather than allowed to say otherwise.
     *
     * @return array<string, mixed>
     */
    private function conversionsFor(AnalyticsRange $range, int $clicks, bool $detailed): array
    {
        $rows = $this->conversionsWithin($range)
            ->selectRaw('event, currency, COUNT(*) as conversions, SUM(value) as amount')
            ->groupBy('event', 'currency')
            ->get();

        $convertedClicks = (int) $this->conversionsWithin($range)->distinct()->count('conversions.click_id');

        $result = [
            'total' => (int) $rows->sum('conversions'),
            'rate' => $clicks > 0 ? min(1.0, round($convertedClicks / $clicks, 4)) : null,
            'revenue' => $this->revenueOf($rows),
        ];

        if ($detailed) {
            $result['by_event'] = $rows->groupBy('event')
                ->map(fn ($group, $event) => [
                    'event' => $event,
                    'conversions' => (int) $group->sum('conversions'),
                    'revenue' => $this->revenueOf($group),
                ])
                ->sortByDesc('conversions')
                ->take(10)
                ->values();
        }

        return $result;
    }

    /**
     * Money is only ever added within one currency. Rows without a value
     * (a signup) carry no currency and add nothing.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array{currency: string, amount: float}>
     */
    private function revenueOf(Collection $rows): Collection
    {
        return $rows->filter(fn ($row) => $row->currency !== null && $row->amount !== null)
            ->groupBy('currency')
            ->map(fn ($group, $currency) => ['currency' => $currency, 'amount' => round((float) $group->sum('amount'), 2)])
            ->sortByDesc('amount')
            ->values();
    }
}
