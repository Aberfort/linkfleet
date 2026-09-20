<?php

namespace App\Http\Controllers;

use App\Models\Click;
use App\Models\Link;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    public function site(Site $site)
    {
        $this->authorize('view', $site);

        return $this->build(Click::query()->whereIn('link_id', $site->links()->pluck('id')), [
            // site_id and password are selected only so the appended
            // short_url / has_password come out right; password stays hidden.
            'top_links' => $site->links()
                ->orderByDesc('clicks_count')
                ->limit(10)
                ->get(['id', 'site_id', 'short_code', 'target_url', 'clicks_count', 'password'])
                ->each(fn (Link $link) => $link->setRelation('site', $site->loadMissing('customDomain'))),
        ]);
    }

    public function link(Link $link)
    {
        $this->authorize('view', $link);

        return $this->build(Click::query()->where('link_id', $link->id));
    }

    private function build(Builder $clicks, array $extra = []): array
    {
        $since = Carbon::now()->subDays(29)->startOfDay();

        $timeseries = (clone $clicks)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as clicks')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Zero-fill every day in the window so the frontend chart doesn't
        // have to guess at missing dates.
        $filled = collect();
        for ($day = $since->copy(); $day->lte(Carbon::now()); $day->addDay()) {
            $key = $day->toDateString();
            $filled->push([
                'date' => $key,
                'clicks' => (int) ($timeseries[$key]->clicks ?? 0),
            ]);
        }

        return [
            'timeseries' => $filled->values(),
            'referrers' => $this->breakdown($clicks, 'referrer'),
            'browsers' => $this->breakdown($clicks, 'browser'),
            'devices' => $this->breakdown($clicks, 'device_type'),
            ...$extra,
        ];
    }

    private function breakdown(Builder $clicks, string $column)
    {
        return (clone $clicks)
            ->selectRaw("COALESCE($column, 'Unknown') as label, COUNT(*) as clicks")
            ->groupBy('label')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get();
    }
}
