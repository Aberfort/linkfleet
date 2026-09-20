<?php

return [
    // Public demo deployments disable self-registration to avoid the demo
    // becoming a spam-account (and open /r/* redirect abuse) target. The
    // register endpoint/UI stays in the codebase either way.
    'registration_enabled' => env('REGISTRATION_ENABLED', true),

    // What a customer's DNS should point at (CNAME, or A records that resolve
    // to the same place). Defaults to the app's own host; deployments behind
    // a platform that hands out a per-domain target (Railway does) override it.
    'custom_domain_target' => env('CUSTOM_DOMAIN_CNAME_TARGET')
        ?: parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST),

    // Where customer domains are looked up. DNS over HTTPS rather than the
    // system resolver so every lookup has a hard timeout (see DohResolver).
    'doh_url' => env('DNS_OVER_HTTPS_URL', 'https://cloudflare-dns.com/dns-query'),

    // Optional: seeds a verified custom domain onto the demo account's Docs
    // site, so a public demo can show a branded address working for real.
    'demo_domain' => env('DEMO_DOMAIN'),
];
