<?php

return [
    // Public demo deployments disable self-registration to avoid the demo
    // becoming a spam-account (and open /r/* redirect abuse) target. The
    // register endpoint/UI stays in the codebase either way.
    'registration_enabled' => env('REGISTRATION_ENABLED', true),

    // Requests per minute. A session is limited per user, an API key per key
    // (so one noisy integration cannot starve the others, or the dashboard).
    'api_rate_limit' => (int) env('API_RATE_LIMIT', 60),
    'api_key_rate_limit' => (int) env('API_KEY_RATE_LIMIT', 120),

    // The most rows one CSV export will hold, so a single request cannot
    // stream the whole clicks table.
    'analytics_export_row_limit' => (int) env('ANALYTICS_EXPORT_ROW_LIMIT', 100_000),

    // What a customer's DNS should point at (CNAME, or A records that resolve
    // to the same place). Defaults to the app's own host; deployments behind
    // a platform that hands out a per-domain target (Railway does) override it.
    'custom_domain_target' => env('CUSTOM_DOMAIN_CNAME_TARGET')
        ?: parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST),

    // Where customer domains are looked up. DNS over HTTPS rather than the
    // system resolver so every lookup has a hard timeout (see DohResolver).
    'doh_url' => env('DNS_OVER_HTTPS_URL', 'https://cloudflare-dns.com/dns-query'),

    'conversions' => [
        // How long after a click a conversion is still credited to it.
        'attribution_days' => (int) env('CONVERSION_ATTRIBUTION_DAYS', 90),
    ],

    'webhooks' => [
        // Webhooks make this server call addresses customers type in. By
        // default only public https endpoints are allowed, so a webhook
        // cannot be aimed at the cloud metadata service or the internal
        // network. A self-hoster who wants to notify a service on their own
        // LAN turns this on (it also permits plain http).
        'allow_private_targets' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_TARGETS', false),
        'max_per_workspace' => 10,
    ],

    // Optional: seeds a verified custom domain onto the demo account's Docs
    // site, so a public demo can show a branded address working for real.
    'demo_domain' => env('DEMO_DOMAIN'),
];
