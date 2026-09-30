<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsRequest;
use App\Models\Click;
use App\Models\Link;
use App\Models\Site;
use App\Support\AnalyticsRange;
use App\Support\AnalyticsReport;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function site(AnalyticsRequest $request, Site $site)
    {
        $this->authorize('view', $site);

        $range = $request->range();

        return [
            ...(new AnalyticsReport($this->siteClicks($site)))->build($range, $request->wantsComparison()),
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
                ->each(fn (Link $link) => $link->setRelation('site', $site->loadMissing('customDomain'))),
        ];
    }

    public function link(AnalyticsRequest $request, Link $link)
    {
        $this->authorize('view', $link);

        return (new AnalyticsReport(Click::query()->where('clicks.link_id', $link->id)))
            ->build($request->range(), $request->wantsComparison());
    }

    public function exportSite(AnalyticsRequest $request, Site $site): StreamedResponse
    {
        $this->authorize('view', $site);

        return $this->csv($this->siteClicks($site), $request->range(), $site->name);
    }

    public function exportLink(AnalyticsRequest $request, Link $link): StreamedResponse
    {
        $this->authorize('view', $link);

        return $this->csv(Click::query()->where('clicks.link_id', $link->id), $request->range(), $link->short_code);
    }

    /** A subquery, not a list of ids: a site with thousands of links must not become a thousand-item IN(). */
    private function siteClicks(Site $site): Builder
    {
        return Click::query()->whereIn('clicks.link_id', Link::query()->select('id')->where('site_id', $site->id));
    }

    /**
     * One row per click, oldest first. No IP address in any form: the export
     * is for spreadsheets that get emailed around.
     */
    private function csv(Builder $clicks, AnalyticsRange $range, string $name): StreamedResponse
    {
        $filename = sprintf('linkfleet-%s-%s_%s.csv', $this->slug($name), $range->from->toDateString(), $range->to->toDateString());

        return response()->streamDownload(function () use ($clicks, $range) {
            $out = fopen('php://output', 'w');
            // A byte-order mark so Excel reads the file as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['time_utc', 'link', 'referrer', 'browser', 'browser_version', 'platform', 'device_type'], ',', '"', '');

            (new AnalyticsReport($clicks))->within($range)
                ->join('links', 'links.id', '=', 'clicks.link_id')
                ->select(['clicks.id', 'clicks.created_at', 'links.short_code', 'clicks.referrer', 'clicks.browser', 'clicks.browser_version', 'clicks.platform', 'clicks.device_type'])
                ->orderBy('clicks.id')
                ->lazyById(1000, 'clicks.id', 'id')
                // On the stream, not as a ->limit() on the query: lazyById
                // pages with a limit of its own and would quietly ignore one.
                ->take((int) config('features.analytics_export_row_limit'))
                ->each(fn ($click) => fputcsv($out, array_map($this->safeCell(...), [
                    $click->created_at,
                    $click->short_code,
                    $click->referrer,
                    $click->browser,
                    $click->browser_version,
                    $click->platform,
                    $click->device_type,
                ]), ',', '"', ''));

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
}
