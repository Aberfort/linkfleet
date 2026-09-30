<?php

namespace Tests\Unit;

use App\Support\AnalyticsRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AnalyticsRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-30 15:45:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_by_default_it_is_the_thirty_days_ending_today(): void
    {
        $range = AnalyticsRange::make(null, null);

        $this->assertSame('2026-09-01', $range->from->toDateString());
        $this->assertSame('2026-09-30', $range->to->toDateString());
        $this->assertSame(30, $range->days());
    }

    public function test_both_ends_are_included_in_the_count(): void
    {
        $this->assertSame(1, AnalyticsRange::make('2026-09-10', '2026-09-10')->days());
        $this->assertSame(10, AnalyticsRange::make('2026-09-10', '2026-09-19')->days());
    }

    public function test_a_missing_end_means_today_and_a_missing_start_means_thirty_days_back(): void
    {
        $this->assertSame('2026-09-30', AnalyticsRange::make('2026-09-20', null)->to->toDateString());
        $this->assertSame('2026-08-22', AnalyticsRange::make(null, '2026-09-20')->from->toDateString());
    }

    public function test_the_range_covers_whole_days_and_ends_at_the_start_of_the_next(): void
    {
        $range = AnalyticsRange::make('2026-09-10', '2026-09-19');

        $this->assertSame('2026-09-10 00:00:00', $range->start()->toDateTimeString());
        // Exclusive: a query using `< end()` includes 23:59:59 on the 19th and nothing of the 20th.
        $this->assertSame('2026-09-20 00:00:00', $range->end()->toDateTimeString());
    }

    public function test_the_previous_range_is_the_same_length_directly_before(): void
    {
        $previous = AnalyticsRange::make('2026-09-10', '2026-09-19')->previous();

        $this->assertSame('2026-08-31', $previous->from->toDateString());
        $this->assertSame('2026-09-09', $previous->to->toDateString());
        $this->assertSame(10, $previous->days());
    }

    public function test_the_previous_of_a_single_day_is_the_day_before(): void
    {
        $previous = AnalyticsRange::make('2026-09-10', '2026-09-10')->previous();

        $this->assertSame('2026-09-09', $previous->from->toDateString());
        $this->assertSame('2026-09-09', $previous->to->toDateString());
    }

    public function test_last_days_ends_today_and_counts_today(): void
    {
        $range = AnalyticsRange::lastDays(7);

        $this->assertSame('2026-09-24', $range->from->toDateString());
        $this->assertSame('2026-09-30', $range->to->toDateString());
        $this->assertSame(7, $range->days());
        $this->assertSame(1, AnalyticsRange::lastDays(1)->days());
    }

    public function test_it_describes_itself_for_the_response(): void
    {
        $this->assertSame(
            ['from' => '2026-09-10', 'to' => '2026-09-19', 'days' => 10],
            AnalyticsRange::make('2026-09-10', '2026-09-19')->toArray()
        );
    }
}
