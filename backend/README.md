# LinkFleet — Backend

Laravel 12 API: multi-tenant Sites/Links, the `/r/{code}` redirect endpoint, and click analytics. See the [root README](../README.md) for the overall architecture.

## Requirements

- PHP >= 8.2
- Composer
- SQLite (zero setup, recommended for local dev) or MySQL

## Setup

```bash
cp .env.example .env
composer install
php artisan key:generate
touch database/database.sqlite   # only if using the default sqlite connection
php artisan migrate --seed
php artisan serve
```

The API is now at `http://localhost:8000`. Seeding creates a read-only demo account (`demo@linkfleet.app` / `demo12345`, see [`DemoUserSeeder`](database/seeders/DemoUserSeeder.php)) plus a couple of sample sites/links/clicks so there's something to look at (see [`DemoDataSeeder`](database/seeders/DemoDataSeeder.php)). Both seeders are idempotent — safe to re-run.

Webhook deliveries are queued, so also run a worker in a second terminal (the queue lives in the database):

```bash
php artisan queue:work
```

To use MySQL instead, uncomment the `DB_*` block in `.env.example` (matches `docker-compose.yml`).

## Auth model

Sanctum bearer tokens, fully stateless — no session cookies, no CSRF dance. `POST /api/login` / `/api/register` return a token; send it as `Authorization: Bearer <token>` on everything else.

Access is enforced by [Policies](app/Policies), not ad-hoc controller checks. Sites belong to a **workspace**, and a user reaches a site only through their role in that workspace — `User::workspaceRoles()` / `roleIn()` is the single place that answers "what may this user reach?", so policies and list endpoints cannot drift apart. Demo-account read-only enforcement is a single [`Gate::before()`](app/Providers/AppServiceProvider.php) hook, so it applies uniformly regardless of resource type.

### Who the visitor is, behind a proxy

Everything that depends on the visitor's address - the hash behind "unique visitors", the country, and every per-address rate limit - reads `$request->ip()`. Laravel derives that from `X-Forwarded-For`, trusting only the proxy that connected to it, which is right with one proxy in front (the bundled nginx) and wrong with two. Railway's edge has more than one: the app was handed an intermediate proxy in another country as "the visitor", so all visitors looked like a single one and the pixel's and login's per-address limits were one shared bucket for the world. It went unnoticed until geography named the wrong country.

`CLIENT_IP_HEADER` (`RealIpFromHeader`) fixes that where it is set: it takes the address from the header your platform sets and overwrites (`X-Real-IP` on Railway, per Railway's own staff; a client cannot supply it), and rewrites `REMOTE_ADDR` and `X-Forwarded-For` to match so every reader agrees. Do not set it if the app can be reached without going through that proxy - then the header is just something a visitor writes. `X-Forwarded-Proto` is left alone, so generated links stay `https`.

### Geography

Each click gets a two-letter `country`, worked out while the request is handled, from a database file on this server (`Support\GeoIp` over `maxmind-db/reader`). The address never leaves the process and is not stored — only the country is (the hash in `ip_hash` is still all that remains of the address). With no database, or an unreadable one, the answer is simply "unknown": a redirect never depends on it, and a corrupt file is reported once an hour instead of once per click.

`php artisan geoip:update` installs the file. The default source is DB-IP's free country database, which needs no account or key; it is CC BY 4.0, so the dashboard shows the credit (`GEOIP_ATTRIBUTION`). It tries this month's file, then last month's (the new one appears at the start of the month), and refuses to replace a working database with one that is not a valid country database or knows nothing about well-known addresses. Set `GEOIP_DOWNLOAD_URL` to use any other `.mmdb`/`.mmdb.gz` (say your own MaxMind GeoLite2 mirror). `docker/railway-start.sh` runs it in the background at boot and hourly with `--if-stale`, so a long-lived container still picks up each new month.

It is an estimate, and worth saying so: country only (no city — that would be a bigger claim and a bigger database), wrong for VPN and mobile-carrier traffic, and only as good as the file. A click behind a private or unrouted address is "Unknown". Tests build a real, valid MaxMind DB file on the fly (`tests/Support/MmdbFixture`, itself tested against the real reader) rather than mock the reader or ship a binary.

### Conversions

Every click gets a random 24-character `token` (unrelated to the visitor). With `sites.conversion_tracking` on, `RedirectController` appends it to the destination as `lf_click` (`Support\ConversionUrl` edits the raw query string so the destination's own parameters are untouched). A conversion arrives two ways, both through `Actions\RecordConversion`: `POST /api/conversions` (authenticated, `source=server`) and the public `GET /lf.gif` pixel (`source=pixel`, anyone holding the token could send it). `public/lf.js` is the browser snippet; its tests live in the frontend suite because that is where a browser-like environment exists.

Decisions worth knowing: `external_id` is stored as `''`, not `NULL`, because a unique index treats every NULL as different and a retry would slip past it; the unique key is `(click, event, external_id)`, with a race handled by catching the violation; a click is credited for 90 days (`CONVERSION_ATTRIBUTION_DAYS`); the pixel always answers with the image so a valid token cannot be told from an invalid one; a token from a workspace you are not in answers 404, like an unknown one. Revenue is summed per currency only, and the conversion rate counts a click once however many orders it made and is capped at 100%.

### Webhooks

Per workspace, owner-only. Events (`link.created|updated|deleted|clicked`) come from a model observer on `Link` and from `RecordLinkClick`, through `Support\WebhookDispatcher`, which costs one query when nobody is subscribed and swallows its own failures — a broken webhook must never cost anyone a redirect. Deliveries are queued (`DeliverWebhook`) and sent by a worker, never inside the request; `docker/railway-start.sh` starts one beside the web server (`QUEUE_WORKERS` for more), and locally you run `php artisan queue:work`. Failed attempts are retried with 10 s / 1 min / 5 min / 30 min backoff, and every attempt is a row in `webhook_deliveries` (trimmed to the latest hundred).

The reason this gets its own paragraph: the server calls addresses that customers type in. `Support\OutboundUrlGuard` is the defence against that turning into server-side request forgery.

- The URL is taken apart and **rebuilt** from parts that passed strict checks, so a hostname PHP and curl would read differently cannot slip through. Backslashes, whitespace, credentials, odd ports, non-ASCII names, and numeric hosts curl would read as IPv4 (`127.1`, `0x7f.0.0.1`, `2130706433`) are refused outright.
- The name is resolved **here** (A and AAAA, over DoH with a timeout), and every address must be public. One private answer among several condemns the name. Private means loopback, RFC 1918, link-local (cloud metadata), CGNAT, multicast, reserved, IPv6 unique-local, and IPv4 hidden inside IPv6 (`::ffff:`, NAT64, 6to4).
- The connection goes to the address that was checked (`CURLOPT_RESOLVE`), so DNS changing between the check and the request does not help. It is checked again on every delivery.
- Redirects are not followed, replies are cut off at 1 MB by curl, and only the first kilobyte is kept.

Two traps found while building it, both pinned by tests: Guzzle's `stream` option silently swaps curl for a handler that ignores `CURLOPT_RESOLVE` (which would drop the pinning), and `Http::fake` cannot show which handler would run — so the size limit is done in curl instead, and verified against a real endless HTTP reply.

Requests are signed `t=<time>,v1=HMAC-SHA256(secret, "<t>.<body>")` (`Support\WebhookSignature`); the secret is encrypted at rest and shown once. `WEBHOOKS_ALLOW_PRIVATE_TARGETS=true` lifts the address rule for self-hosters who want to notify something on their LAN. Receiver-side recipes are in [`docs/API.md`](../docs/API.md#webhooks) and were run against real signatures.

Known limits: one worker sends one delivery at a time (a very busy `link.clicked` webhook can queue up — raise `QUEUE_WORKERS`); a failing endpoint is never switched off automatically; deleting a site or workspace removes its links below the model events, so `link.deleted` is not sent for each.

### API keys

A key is an ordinary Sanctum token that is not a full session: it carries `read` (and optionally `write`) instead of `*`, and can be pinned to one workspace with a `workspace:<id>` ability. Keeping the scheme in the ability list means no extra table and no custom token model; `Support\ApiKeyScope` is the only place that knows the spelling.

- `EnforceKeyScope` lets a read key make only requests that cannot change anything; everything else needs `write`.
- A pinned key is narrowed in `User::workspaceRoles()` — the one place every policy and list endpoint already asks — so no resource can forget to honour the pin.
- `RequireSession` keeps key management itself off-limits to keys, so a leaked write key cannot mint more keys and outlive its own revocation.
- Only a hash is stored; the plaintext exists in the create response and nowhere else. Tokens start with `lf_`.
- Sessions are throttled per user (`API_RATE_LIMIT`, 60/min), keys per key (`API_KEY_RATE_LIMIT`, 120/min), so one noisy integration cannot starve another or the dashboard.

The API reference for users is [`docs/API.md`](../docs/API.md).

### Workspaces and roles

Three coarse roles instead of a permission matrix:

| | viewer | editor | owner |
|---|:-:|:-:|:-:|
| Read sites, links, analytics, domain | ✓ | ✓ | ✓ |
| Create and change sites and links, import CSV | | ✓ | ✓ |
| Delete a link | | ✓ | ✓ |
| Delete a site (takes its links and clicks along) | | | ✓ |
| Attach, verify and remove a custom domain | | | ✓ |
| Rename or delete the workspace, manage members | | | ✓ |

`tests/Feature/RoleMatrixTest.php` checks this table rather than trusting it. Any member may leave a workspace; a workspace can never be left without an owner (the check locks the workspace row, so two owners cannot demote each other at the same moment). Members are added by the email of an existing account — there is no invitation flow. A workspace the caller cannot see is answered like one that does not exist, so ids cannot be probed.

Every user gets a workspace at registration. The migration that introduced workspaces gave each existing user one and moved their sites into it, so nothing changed for them; it was run against MySQL with old-shape data, rolled back, and re-applied before it shipped.

## API

| Method | Path | Auth | |
|---|---|---|---|
| POST | `/api/register` | — | Gated by `REGISTRATION_ENABLED` |
| POST | `/api/login` | — | |
| POST | `/api/logout` | ✓ | |
| GET | `/api/user` | ✓ | |
| GET | `/api/config` | — | `{ registration_enabled, custom_domain_target }` |
| GET/POST | `/api/workspaces/{workspace}/webhooks` | ✓ owner | list / create (the secret is returned once) |
| GET/PUT/DELETE | `/api/webhooks/{webhook}` | ✓ owner | |
| POST | `/api/webhooks/{webhook}/rotate-secret`, `/test` | ✓ owner | `/test` sends a `ping` now |
| GET | `/api/webhooks/{webhook}/deliveries` | ✓ owner | latest 50 attempts |
| GET/POST | `/api/api-keys` | ✓ session | list / create (returns the key once) |
| DELETE | `/api/api-keys/{id}` | ✓ session | revoke |
| GET/POST | `/api/workspaces` | ✓ | workspaces you belong to, each with your `role` |
| GET/PUT/DELETE | `/api/workspaces/{workspace}` | ✓ | |
| GET/POST | `/api/workspaces/{workspace}/members` | ✓ | add by `{ email, role }` |
| PATCH/DELETE | `/api/workspaces/{workspace}/members/{user}` | ✓ | change role / remove (or leave, for yourself) |
| GET/POST | `/api/sites` | ✓ | every site across your workspaces; create needs `workspace_id` |
| GET/PUT/DELETE | `/api/sites/{site}` | ✓ | |
| GET/POST | `/api/sites/{site}/links` | ✓ | |
| POST | `/api/sites/{site}/links/import` | ✓ | CSV upload, see below |
| GET/PUT/DELETE | `/api/links/{link}` | ✓ | |
| PATCH | `/api/links/{link}/toggle` | ✓ | flips `is_active` |
| POST | `/api/conversions` | ✓ write | report a conversion; idempotent |
| GET | `/api/sites/{site}/conversions` | ✓ | the latest 50 |
| GET | `/lf.gif`, `/lf.js` | — | the conversion pixel and browser snippet |
| GET | `/api/sites/{site}/analytics` | ✓ | rolled up across all its links; `days` or `from`/`to`, `compare=previous`; includes conversions |
| GET | `/api/links/{link}/analytics` | ✓ | single link, same parameters |
| GET | `/api/sites/{site}/analytics/export`, `/api/links/{link}/analytics/export` | ✓ | clicks or (`type=conversions`) conversions as CSV |
| GET | `/api/sites/{site}/domain` | ✓ | `{ domain: … \| null }` |
| POST | `/api/sites/{site}/domain` | ✓ | attach a custom domain, replacing any existing one |
| POST | `/api/domains/{domain}/verify` | ✓ | DNS TXT check; 422 while the record is missing |
| POST | `/api/domains/{domain}/check` | ✓ | `{ target, dns, https }` — is it wired up yet |
| DELETE | `/api/domains/{domain}` | ✓ | |
| GET | `/r/{code}` | — | the actual redirect (302 + click logging) |
| POST | `/r/{code}` | — | password gate submit |
| GET | `/qr/{code}.svg` | — | QR code for the short link |
| GET/POST | `/{code}` | — | same redirect, on a verified custom domain |

### Link options

- **Expiry** (`expires_at`): after it passes, `/r/{code}` answers `410 Gone` and logs nothing.
- **Password** (`password`): visitors get a server-rendered gate first; the click is only recorded once they're through. The hash is never returned by the API — read `has_password` instead. On update, omitting the key leaves the password unchanged; sending it empty removes it.
- **QR codes** are public and generated on the fly, so they can be embedded directly as `<img src="…/qr/{code}.svg">`.

### Custom domains

A site can claim one hostname, and getting it live is three separate steps — each one checked, so the UI can say which is still missing:

1. **Ownership.** A DNS TXT record, `_linkfleet.<host>`, holding the domain's `verification_token`. `POST /api/domains/{domain}/verify` reads it.
2. **Pointing.** A CNAME to `CUSTOM_DOMAIN_CNAME_TARGET` (defaults to the app's own host), or A records resolving to the same addresses for an apex domain.
3. **Certificate.** Something in front of the app must terminate TLS for that host. `POST /api/domains/{domain}/check` reports `{ dns, https }` — DNS pointing at the target, then `https://<host>/up` answering with a valid certificate — and doesn't even try HTTPS while DNS is still wrong.

Once verified, that host serves the site's links at the root: `go.example.com/summer-sale` resolves the same link as `/r/summer-sale`, logs the same click, and honours the same expiry and password gate. The catch-all route is registered last in `routes/web.php` and only matches when the request's `Host` belongs to a *verified* domain, so `/`, `/up`, `/r/…` and `/qr/…` keep their meaning and an unverified or unknown host gets a 404. Links and QR codes report the branded address through their `short_url`.

All lookups go through `Support\DohResolver` (DNS over HTTPS, `DNS_OVER_HTTPS_URL`) rather than the system resolver: the names are customer-supplied, and a nameserver that never answers would otherwise hold a PHP worker for as long as the resolver cares to wait. Every lookup has a 3 s timeout, and `verify`/`check` are throttled. The HTTPS probe in `check` requests a host the customer typed in, so it goes through the same `OutboundUrlGuard` as webhooks (refused if it leads into a private network, pinned to the address that was checked, no redirects) — otherwise "is my domain ready?" would double as a way to ask the server what answers on `/up` inside its own network. Tests fake the HTTP layer, so none touch the network.

`check` makes the app request itself once a domain points at it, so it needs more than one server worker. `docker/railway-start.sh` starts `artisan serve` with four (`PHP_CLI_SERVER_WORKERS`, which Laravel only honours together with `--no-reload`); with PHP's default single-process server the probe waits out its timeout and always reports no HTTPS.

What LinkFleet does **not** do is issue certificates or register the host with your platform — that's the part outside the app. On Railway it means adding the domain to the backend service; on a VPS, a reverse proxy such as Caddy in front of the app. Also still open: per-domain short codes. `links.short_code` stays globally unique, so two sites can't both own `summer-sale`.

Set `DEMO_DOMAIN` to have the seeder attach a pre-verified domain to the demo account's Docs site — only do that on a deployment that has really pointed the host at itself.

### CSV import

Expects a header row containing `target_url`, optionally `short_code`:

```csv
target_url,short_code
https://example.com/promo,summer-sale
https://example.com/docs,
```

Rows are validated individually and capped at 1000 per file — a bad row is skipped and reported back with its line number and reason rather than failing the whole upload. A blank `short_code` is auto-generated.

## Tests

```bash
vendor/bin/phpunit
```

491 Feature/Unit tests — auth flow, ownership boundaries (cross-user 403s, demo-account write blocks), the redirect+click-logging path, analytics aggregation, custom-domain verification and host-based routing. `phpunit.xml` runs against an in-memory SQLite database, so no service container/setup needed.

```bash
vendor/bin/pint          # check code style
vendor/bin/pint --dirty  # fix it
```

## Structure

```
app/
  Actions/          RecordLinkClick - the redirect endpoint's core logic
  Http/Controllers/
  Http/Requests/     Validation + authorization (FormRequest::authorize())
  Enums/            WorkspaceRole, WebhookEvent
  Jobs/             DeliverWebhook
  Observers/        LinkObserver - turns link changes into webhook events
  Models/            User, Workspace, Site, Link, Click, Domain
  Policies/          Role checks (Workspace, Site, Link, Domain)
  Support/           UserAgentParser, ClientIp, GeoIp, ApiKeyScope, OutboundUrlGuard, WebhookSender/Dispatcher/Signature, DohResolver, DnsTxtLookup, DomainProbe - small helpers; the DNS ones are test seams
database/
  migrations/
  seeders/           DemoUserSeeder, DemoDataSeeder (idempotent, run on every deploy)
routes/
  api.php            JSON API, auth:sanctum-protected where noted above
  web.php            Redirects, QR codes and the custom-domain catch-all -
                     real browser navigation, not JSON
```
