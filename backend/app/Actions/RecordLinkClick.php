<?php

namespace App\Actions;

use App\Enums\WebhookEvent;
use App\Models\Click;
use App\Models\Link;
use App\Support\ClientIp;
use App\Support\UserAgentParser;
use App\Support\WebhookDispatcher;
use App\Support\WebhookPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecordLinkClick
{
    public function __construct(
        private readonly UserAgentParser $userAgentParser,
        private readonly ClientIp $clientIp,
    ) {}

    public function handle(Link $link, Request $request): Click
    {
        $ua = $this->userAgentParser->parse($request->userAgent());

        $click = $link->clicks()->create([
            // Every click gets one, tracking or not, so switching tracking on
            // later never leaves a gap in what can be attributed.
            'token' => Str::random(24),
            'ip_hash' => $this->clientIp->truncateAndHash($request->ip()),
            'referrer' => $this->referrerHost($request->header('referer')),
            'user_agent' => $request->userAgent(),
            ...$ua,
        ]);

        $link->increment('clicks_count');

        WebhookDispatcher::dispatch(WebhookEvent::LinkClicked, $link->site_id, fn () => [
            'link' => WebhookPayload::link($link),
            'click' => WebhookPayload::click($click),
        ]);

        return $click;
    }

    /**
     * Store just the referring host, not the full URL - keeps the
     * analytics breakdown groupable by domain (rather than fragmenting on
     * every distinct path/query string) and avoids logging whatever a
     * search query or private document path in a referrer URL might leak.
     */
    private function referrerHost(?string $referrer): ?string
    {
        if (empty($referrer)) {
            return null;
        }

        return parse_url($referrer, PHP_URL_HOST) ?: null;
    }
}
