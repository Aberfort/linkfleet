<?php

namespace Database\Seeders;

use App\Models\Click;
use App\Models\Conversion;
use App\Models\Link;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Idempotent by design - safe to run on every deploy alongside
 * DemoUserSeeder. Gives the read-only demo account something to actually
 * look at (empty tables would defeat the point of a public demo).
 */
class DemoDataSeeder extends Seeder
{
    // Just the host, matching what RecordLinkClick actually stores for
    // real traffic (see App\Actions\RecordLinkClick::referrerHost).
    private const REFERRERS = [
        'twitter.com',
        'www.google.com',
        'www.facebook.com',
        null, // direct traffic
    ];

    private const BROWSERS = [
        ['browser' => 'Chrome', 'browser_version' => '129.0', 'platform' => 'Windows', 'device_type' => 'desktop'],
        ['browser' => 'Safari', 'browser_version' => '17.6', 'platform' => 'iOS', 'device_type' => 'mobile'],
        ['browser' => 'Firefox', 'browser_version' => '131.0', 'platform' => 'macOS', 'device_type' => 'desktop'],
        ['browser' => 'Chrome', 'browser_version' => '129.0', 'platform' => 'Android', 'device_type' => 'mobile'],
    ];

    public function run(): void
    {
        $user = User::query()->where('email', DemoUserSeeder::EMAIL)->first();

        if (! $user) {
            return;
        }

        $workspace = $user->defaultWorkspace();

        $marketing = Site::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Marketing Site'],
            ['domain' => 'example.com', 'description' => 'Landing pages and social campaigns.']
        );

        $docs = Site::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Docs Site'],
            ['domain' => 'docs.example.com', 'description' => 'Public documentation redirects.']
        );

        $this->seedLink($marketing, 'homepage', 'https://example.com/', 220);
        $this->seedLink($marketing, 'twitter-promo', 'https://example.com/promo?utm_source=twitter', 140);
        $this->seedLink($marketing, 'newsletter', 'https://example.com/newsletter', 65);
        $this->seedLink($docs, 'getting-started', 'https://docs.example.com/getting-started', 95);
        $this->seedLink($docs, 'api-reference', 'https://docs.example.com/api', 40);

        $this->seedDomain($docs);

        $this->seedConversions($marketing);
        $this->seedConversions($docs);
    }

    /**
     * Turns tracking on and gives a share of the site's existing clicks a
     * plausible outcome, so the demo dashboard shows a real conversions
     * block. Clicks seeded before conversions existed have no token, so the
     * ones picked get one here. Signups arrive "from a browser", purchases
     * "from a server", as in real use. Does nothing if the site already
     * has any, so it is safe on every deploy.
     */
    private function seedConversions(Site $site): void
    {
        $site->update(['conversion_tracking' => true]);

        $linkIds = $site->links()->pluck('id');

        if (Conversion::whereIn('link_id', $linkIds)->exists()) {
            return;
        }

        $clicks = Click::whereIn('link_id', $linkIds);
        $sample = (int) ceil($clicks->count() * 0.09);

        $rows = [];

        foreach ((clone $clicks)->inRandomOrder()->limit($sample)->get() as $click) {
            $click->token ?? $click->forceFill(['token' => Str::random(24)])->save();

            $purchase = rand(1, 100) <= 30;
            $convertedAt = Carbon::parse($click->created_at)->addMinutes(rand(1, 240));

            $rows[] = [
                'click_id' => $click->id,
                'link_id' => $click->link_id,
                'event' => $purchase ? 'purchase' : 'signup',
                'value' => $purchase ? rand(1900, 14900) / 100 : null,
                'currency' => $purchase ? 'USD' : null,
                'external_id' => $purchase ? 'demo-order-'.$click->id : '',
                'source' => $purchase ? Conversion::SOURCE_SERVER : Conversion::SOURCE_PIXEL,
                'created_at' => $convertedAt->isFuture() ? now() : $convertedAt,
            ];
        }

        // A bulk insert bypasses model events on purpose: seeding must not
        // fire webhooks at anyone.
        foreach (array_chunk($rows, 200) as $chunk) {
            Conversion::insert($chunk);
        }
    }

    /**
     * Opt-in through DEMO_DOMAIN: only a deployment that has really pointed
     * a host at itself should advertise one. It's marked verified directly -
     * the demo account is read-only, so it couldn't run the TXT flow itself.
     */
    private function seedDomain(Site $site): void
    {
        $host = config('features.demo_domain');

        if (! $host) {
            return;
        }

        $domain = $site->customDomain()->firstOrNew();
        $domain->host = strtolower($host);
        $domain->verified_at ??= now();
        $domain->save();
    }

    private function seedLink(Site $site, string $shortCode, string $targetUrl, int $clickCount): void
    {
        $link = Link::query()->updateOrCreate(
            ['site_id' => $site->id, 'short_code' => $shortCode],
            ['target_url' => $targetUrl, 'is_active' => true]
        );

        if ($link->clicks()->exists()) {
            return;
        }

        $rows = [];
        for ($i = 0; $i < $clickCount; $i++) {
            $referrer = self::REFERRERS[array_rand(self::REFERRERS)];
            $browser = self::BROWSERS[array_rand(self::BROWSERS)];

            $rows[] = [
                'link_id' => $link->id,
                'token' => Str::random(24),
                'ip_hash' => hash('sha256', 'demo-seed-'.$i.'-'.$link->id),
                'referrer' => $referrer,
                'user_agent' => null,
                'browser' => $browser['browser'],
                'browser_version' => $browser['browser_version'],
                'platform' => $browser['platform'],
                'device_type' => $browser['device_type'],
                'created_at' => Carbon::now()->subDays(rand(0, 29))->subMinutes(rand(0, 1439)),
            ];
        }

        // Chunked bulk insert - this can be a few hundred rows per link,
        // no need for individual Eloquent create() calls (no events to
        // fire, no relationships to touch on each row).
        foreach (array_chunk($rows, 200) as $chunk) {
            $link->clicks()->insert($chunk);
        }

        $link->update(['clicks_count' => $clickCount]);
    }
}
