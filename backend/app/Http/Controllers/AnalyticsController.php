<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsRequest;
use App\Models\Click;
use App\Models\Conversion;
use App\Models\Link;
use App\Models\Site;
use App\Support\AnalyticsRange;
use App\Support\AnalyticsReport;
use App\Support\GeoIp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function site(AnalyticsRequest $request, Site $site)
    {
        $this->authorize('view', $site);

        $range = $request->range();

        $links = Link::query()->select('id')->where('site_id', $site->id);

        return [
            ...(new AnalyticsReport($this->siteClicks($site), Conversion::query()->whereIn('conversions.link_id', $links)))
                ->build($range, $request->wantsComparison()),
            'site_id' => $site->id,
            'conversion_tracking' => $site->conversion_tracking,
            'geo' => $this->geo(),
            // site_id and password are selected only so the appended
            // short_url / has_password come out right; password stays hidden.
            'top_links' => $site->links()
                ->select(['id', 'site_id', 'short_code', 'target_url', 'clicks_count', 'password'])
                ->withCount(['clicks as period_clicks' => fn (Builder $clicks) => $clicks
                    ->where('created_at', '>=', $range->start())
                    ->where('created_at', '<', $range->end())])
                ->orderByDesc('period_clicks')
                ->limit(10)
                ->get()
                ->each(fn (Link $link) => $link->setRelation('site', $site->loadMissing('customDomain')))
                ->pipe(fn ($top) => $this->withConversions($top, $range)),
        ];
    }

    public function link(AnalyticsRequest $request, Link $link)
    {
        $this->authorize('view', $link);

        return [
            ...(new AnalyticsReport(Click::query()->where('clicks.link_id', $link->id), Conversion::query()->where('conversions.link_id', $link->id)))
                ->build($request->range(), $request->wantsComparison()),
            'site_id' => $link->site_id,
            'conversion_tracking' => $link->site->conversion_tracking,
            'geo' => $this->geo(),
        ];
    }

    public function exportSite(AnalyticsRequest $request, Site $site): StreamedResponse
    {
        $this->authorize('view', $site);

        $links = Link::query()->select('id')->where('site_id', $site->id);

        return $request->exportsConversions()
            ? $this->conversionsCsv(Conversion::query()->whereIn('conversions.link_id', $links), $request->range(), $site->name)
            : $this->csv($this->siteClicks($site), $request->range(), $site->name);
    }

    public function exportLink(AnalyticsRequest $request, Link $link): StreamedResponse
    {
        $this->authorize('view', $link);

        return $request->exportsConversions()
            ? $this->conversionsCsv(Conversion::query()->where('conversions.link_id', $link->id), $request->range(), $link->short_code)
            : $this->csv(Click::query()->where('clicks.link_id', $link->id), $request->range(), $link->short_code);
    }

    /**
     * Whether this server can place new clicks in countries, and the credit
     * its data source asks to be shown. Countries already recorded appear
     * either way; this is what lets the page tell "no data yet" from "not
     * set up".
     *
     * @return array{available: bool, attribution: string|null}
     */
    private function geo(): array
    {
        return [
            'available' => app(GeoIp::class)->available(),
            'attribution' => config('features.geoip.attribution') ?: null,
        ];
    }

    /** A subquery, not a list of ids: a site with thousands of links must not become a thousand-item IN(). */
    private function siteClicks(Site $site): Builder
    {
        return Click::query()->whereIn('clicks.link_id', Link::query()->select('id')->where('site_id', $site->id));
    }

    /**
     * One row per click, in the order they were recorded. No IP address in
     * any form: the export is for spreadsheets that get emailed around.
     */
    private function csv(Builder $clicks, AnalyticsRange $range, string $name): StreamedResponse
    {
        return $this->stream(
            $this->filename($name, $range),
            ['time_utc', 'link', 'referrer', 'browser', 'browser_version', 'platform', 'device_type', 'country'],
            (new AnalyticsReport($clicks))->within($range)
                ->join('links', 'links.id', '=', 'clicks.link_id')
                ->select(['clicks.id', 'clicks.created_at', 'links.short_code', 'clicks.referrer', 'clicks.browser', 'clicks.browser_version', 'clicks.platform', 'clicks.device_type', 'clicks.country'])
                ->orderBy('clicks.id')
                ->lazyById(1000, 'clicks.id', 'id'),
            fn ($click) => [$click->created_at, $click->short_code, $click->referrer, $click->browser, $click->browser_version, $click->platform, $click->device_type, $click->country],
        );
    }

    /**
     * One row per conversion. The click token is left out on purpose: whoever
     * holds one can report conversions for that click through the pixel, and
     * an export is the kind of file that gets forwarded.
     */
    private function conversionsCsv(Builder $conversions, AnalyticsRange $range, string $name): StreamedResponse
    {
        return $this->stream(
            $this->filename($name, $range, 'conversions'),
            ['time_utc', 'link', 'event', 'value', 'currency', 'external_id', 'source'],
            (new AnalyticsReport(Click::query(), $conversions))->conversionsWithin($range)
                ->join('links', 'links.id', '=', 'conversions.link_id')
                ->select(['conversions.id', 'conversions.created_at', 'links.short_code', 'conversions.event', 'conversions.value', 'conversions.currency', 'conversions.external_id', 'conversions.source'])
                ->orderBy('conversions.id')
                ->lazyById(1000, 'conversions.id', 'id'),
            fn ($conversion) => [$conversion->created_at, $conversion->short_code, $conversion->event, $conversion->value, $conversion->currency, $conversion->external_id, $conversion->source],
        );
    }

    /**
     * @param  LazyCollection<int, object>  $rows
     * @param  array<int, string>  $header
     * @param  callable(object): array<int, mixed>  $cells
     */
    private function stream(string $filename, array $header, $rows, callable $cells): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows, $cells) {
            $out = fopen('php://output', 'w');
            // A byte-order mark so Excel reads the file as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ',', '"', '');

            // On the stream, not as a ->limit() on the query: lazyById pages
            // with a limit of its own and would quietly ignore one.
            $rows->take((int) config('features.analytics_export_row_limit'))
                ->each(fn ($row) => fputcsv($out, array_map($this->safeCell(...), $cells($row)), ',', '"', ''));

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filename(string $name, AnalyticsRange $range, ?string $kind = null): string
    {
        return sprintf('linkfleet-%s%s-%s_%s.csv', $this->slug($name), $kind ? "-{$kind}" : '', $range->from->toDateString(), $range->to->toDateString());
    }

    /**
     * Spreadsheets run a cell that starts with = + - @ as a formula. Every
     * value here originates from a visitor's browser, so none is trusted.
     */
    private function safeCell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }

    private function slug(string $name): string
    {
        $slug = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $name) ?? '', '-');

        return $slug !== '' ? strtolower($slug) : 'export';
    }

    /**
     * Each of the top links with what it converted in the range, so the table
     * shows which links earn money and not only which get clicked. Two
     * queries for the lot, not two per link.
     *
     * @param  Collection<int, Link>  $links
     * @return Collection<int, Link>
     */
    private function withConversions($links, AnalyticsRange $range)
    {
        $rows = Conversion::query()
            ->whereIn('link_id', $links->pluck('id'))
            ->where('created_at', '>=', $range->start())
            ->where('created_at', '<', $range->end())
            ->selectRaw('link_id, currency, COUNT(*) as conversions, SUM(value) as amount')
            ->groupBy('link_id', 'currency')
            ->get()
            ->groupBy('link_id');

        return $links->each(function (Link $link) use ($rows) {
            $mine = $rows->get($link->id, collect());

            $link->setAttribute('period_conversions', (int) $mine->sum('conversions'));
            $link->setAttribute('period_revenue', $mine
                ->filter(fn ($row) => $row->currency !== null && $row->amount !== null)
                ->map(fn ($row) => ['currency' => $row->currency, 'amount' => round((float) $row->amount, 2)])
                ->sortByDesc('amount')->values());
        });
    }
}
