<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A run of whole calendar days, both ends included. Analytics always asks
 * for whole days - a "last 30 days" that starts at this hour 30 days ago
 * would put a partial day at each end of the chart.
 */
final class AnalyticsRange
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 366;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    /** The last $days days, ending today - "today" being the server's, so a client's clock cannot put the end in the future. */
    public static function lastDays(int $days): self
    {
        $end = CarbonImmutable::today();

        return new self($end->subDays($days - 1), $end);
    }

    /** Both ends optional: a missing `to` is today, a missing `from` is a default window back from `to`. */
    public static function make(?string $from, ?string $to): self
    {
        $end = $to ? CarbonImmutable::parse($to)->startOfDay() : CarbonImmutable::today();
        $start = $from ? CarbonImmutable::parse($from)->startOfDay() : $end->subDays(self::DEFAULT_DAYS - 1);

        return new self($start, $end);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** First instant of the range. */
    public function start(): CarbonImmutable
    {
        return $this->from;
    }

    /** First instant *after* the range, so a query can use `< end` and never miss the last second. */
    public function end(): CarbonImmutable
    {
        return $this->to->addDay();
    }

    /** The same number of days, immediately before this range. */
    public function previous(): self
    {
        $days = $this->days();

        return new self($this->from->subDays($days), $this->from->subDay());
    }

    /** @return array{from: string, to: string, days: int} */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'days' => $this->days(),
        ];
    }
}
