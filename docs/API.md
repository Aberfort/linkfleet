# LinkFleet API

Everything the dashboard does goes through this JSON API, so anything you can click you can script. This page covers authentication, limits and the endpoints; the [backend README](../backend/README.md) has the design notes behind them.

Base URL is wherever your LinkFleet backend lives (`https://backend-production-c365.up.railway.app` for the public demo). All examples send and expect JSON — add `Accept: application/json` so errors come back as JSON too.

## Authentication

Send a token in the `Authorization` header:

```bash
curl https://YOUR-BACKEND/api/sites \
  -H "Authorization: Bearer lf_..." \
  -H "Accept: application/json"
```

There are two kinds of token, and they are deliberately different:

| | Session token | API key |
|---|---|---|
| Comes from | `POST /api/login` | **API-ключі** page in the dashboard |
| Access | everything you can do | `read` (GET only) or `read` + `write` |
| Scope | all your workspaces | all of them, or pinned to one |
| Manage keys | yes | **no** — a key can never create or revoke keys |
| Rate limit | 60 / min per user | 120 / min **per key** |

Use an API key for anything unattended. Give it the least it needs:

- **`read`** keys can only make requests that cannot change anything (`GET`, `HEAD`). A `POST` of any kind, including the read-only `.../check`, needs a `write` key.
- **`write`** keys can do whatever *you* may do — never more. A viewer's write key still cannot create a link.
- **Pinned** keys see one workspace and nothing else: other workspaces are absent from lists and answer `403` if you ask for them by id. Use one per client or per integration.

The key is shown once, when you create it. Only a hash is stored, so a lost key cannot be recovered, only replaced. Revoking a key takes effect on its next request. Keys start with `lf_` so they are easy to spot in logs and to scan for.

## Roles

What you may do inside a workspace depends on your role there. A key inherits it.

| | viewer | editor | owner |
|---|:-:|:-:|:-:|
| Read sites, links, analytics, domain | ✓ | ✓ | ✓ |
| Create and change sites and links, import CSV, delete links | | ✓ | ✓ |
| Delete a site | | | ✓ |
| Attach, verify and remove a custom domain | | | ✓ |
| Manage members, rename or delete the workspace | | | ✓ |

## Errors

Errors are JSON with a `message`. Validation failures add an `errors` object keyed by field:

```json
{
  "message": "The target url field must be a valid URL.",
  "errors": { "target_url": ["The target url field must be a valid URL."] }
}
```

| Status | Meaning |
|---|---|
| `401` | Missing, invalid or revoked token |
| `403` | Authenticated, but not allowed — your role, a read-only key, or a key pinned elsewhere |
| `404` | No such thing — or, on member routes, someone who is not in that workspace |
| `422` | Validation failed |
| `429` | Rate limited — wait `Retry-After` seconds |

## Rate limits

Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`. Over the limit you get `429` and a `Retry-After` header (seconds). Each API key has its own allowance, so one noisy integration cannot starve another or the dashboard. Self-hosting? Change them with `API_RATE_LIMIT` and `API_KEY_RATE_LIMIT`.

## Endpoints

`{site}`, `{link}`, `{workspace}` and `{domain}` are numeric ids.

### Workspaces

| | |
|---|---|
| `GET /api/workspaces` | Workspaces you belong to, each with your `role`, `members_count`, `sites_count` |
| `POST /api/workspaces` | `{ "name" }` — you become its owner |
| `GET` / `PUT` / `DELETE /api/workspaces/{workspace}` | `PUT` takes `{ "name" }`; `DELETE` takes its sites with it |
| `GET /api/workspaces/{workspace}/members` | `[{ user_id, name, email, role, joined_at }]` |
| `POST /api/workspaces/{workspace}/members` | `{ "email", "role" }` — the person must already have an account |
| `PATCH /api/workspaces/{workspace}/members/{user}` | `{ "role" }` |
| `DELETE /api/workspaces/{workspace}/members/{user}` | Remove someone, or leave (your own id). The last owner cannot go |

`role` is one of `owner`, `editor`, `viewer`.

### Sites

| | |
|---|---|
| `GET /api/sites` | Every site across your workspaces, each with `workspace` and your `role` |
| `POST /api/sites` | `{ "workspace_id", "name", "domain"?, "description"? }` — `name` is unique within the workspace |
| `GET` / `PUT` / `DELETE /api/sites/{site}` | A site never moves between workspaces |

### Links

| | |
|---|---|
| `GET /api/sites/{site}/links` | Newest first |
| `POST /api/sites/{site}/links` | see below |
| `POST /api/sites/{site}/links/import` | multipart `file`: a CSV with a `target_url` column, optional `short_code`. Up to 1000 rows; bad rows are skipped and reported, not fatal |
| `GET` / `PUT` / `DELETE /api/links/{link}` | |
| `PATCH /api/links/{link}/toggle` | Flips `is_active` |

Creating a link:

```bash
curl -X POST https://YOUR-BACKEND/api/sites/12/links \
  -H "Authorization: Bearer lf_..." -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"target_url": "https://example.com/spring-sale", "short_code": "spring"}'
```

| Field | |
|---|---|
| `target_url` | required, a URL |
| `short_code` | optional, letters/digits/`-`/`_`; generated when omitted. Globally unique, and not editable afterwards — a shared link must not change under people |
| `expires_at` | optional, a future date. After it the link answers `410 Gone` |
| `password` | optional, at least 4 characters. Visitors get a password page first; the click counts once they are through. Send it empty on update to remove it |

A link comes back with `short_url` — the address to actually share. It is the site's own domain (`https://go.example.com/spring`) once one is verified, and `/r/{code}` on the LinkFleet host otherwise. `has_password` tells you whether a password is set; the password itself is never returned.

### Analytics

| | |
|---|---|
| `GET /api/sites/{site}/analytics` | Across all the site's links, plus `top_links` |
| `GET /api/links/{link}/analytics` | One link |
| `GET /api/sites/{site}/analytics/export` | Every click as CSV (see below) |
| `GET /api/links/{link}/analytics/export` | The same for one link |

All four take the same query:

| Parameter | |
|---|---|
| `days` | The last N days ending today, 1–366. Resolved on the server's clock, so prefer it to computing dates yourself. Cannot be combined with `from`/`to` |
| `from`, `to` | `YYYY-MM-DD`, both ends included. Either may be omitted (`to` defaults to today, `from` to 30 days before `to`). At most 366 days, and `to` may not be in the future. Days are UTC days |
| `compare` | `previous` adds the period of equal length immediately before this one |
| `type` | Export only: `clicks` (default) or `conversions` |

With none of them you get the last 30 days.

```json
{
  "range": { "from": "2026-09-01", "to": "2026-09-30", "days": 30 },
  "totals": { "clicks": 1043, "visitors": 388 },
  "timeseries": [{ "date": "2026-09-01", "clicks": 12 }],
  "referrers": [{ "label": "twitter.com", "clicks": 40 }],
  "browsers": [{ "label": "Chrome", "clicks": 90 }],
  "devices": [{ "label": "mobile", "clicks": 70 }],
  "countries": [{ "label": "UA", "clicks": 310 }, { "label": "DE", "clicks": 120 }, { "label": "Unknown", "clicks": 44 }],
  "geo": { "available": true, "attribution": "IP Geolocation by DB-IP" },
  "site_id": 12,
  "conversion_tracking": true,
  "conversions": {
    "total": 41,
    "rate": 0.0387,
    "revenue": [{ "currency": "USD", "amount": 1830.5 }, { "currency": "EUR", "amount": 240 }],
    "by_event": [{ "event": "purchase", "conversions": 29, "revenue": [{ "currency": "USD", "amount": 1830.5 }] }]
  },
  "top_links": [{ "id": 71, "short_code": "spring", "short_url": "https://go.example.com/spring", "period_clicks": 210, "clicks_count": 4301, "period_conversions": 14, "period_revenue": [{ "currency": "USD", "amount": 902 }] }],
  "previous": {
    "range": { "from": "2026-08-02", "to": "2026-08-31", "days": 30 },
    "totals": { "clicks": 870, "visitors": 401 },
    "timeseries": [{ "date": "2026-08-02", "clicks": 9 }],
    "conversions": { "total": 30, "rate": 0.0322, "revenue": [{ "currency": "USD", "amount": 1500 }] }
  }
}
```

`countries` is the ten biggest, as ISO 3166-1 alpha-2 codes (`UA`), with `Unknown` for clicks that could not be placed — everything recorded before countries existed, and any address the database does not cover. Equal counts are listed alphabetically, so the order does not change between loads. `geo.available` says whether *this server* can place new clicks (it has a country database); countries already recorded are returned either way. If you show countries, show `geo.attribution` with them: the default data source (DB-IP Lite, CC BY 4.0) asks for it.

`conversions` counts what visitors did in the range (see [Conversions](#conversions)); `rate` is the share of the range's clicks that were followed by a conversion, counting a click once however many orders it led to, capped at 1 because a conversion can belong to a click from before the range, and `null` when there were no clicks. `revenue` is summed within each currency and never across them. `by_event` (not repeated under `previous`) is the ten busiest events. `conversion_tracking` says whether the site is set up to record any.

`timeseries` has one entry per day in the range, quiet days as zero. `previous` is present only with `compare=previous`. `top_links` (site only) is ranked by `period_clicks` — clicks inside the range — and also carries the all-time `clicks_count`.

`visitors` is **approximate**: to avoid storing IP addresses, LinkFleet truncates each to its /24 network and hashes it, and `visitors` counts distinct hashes. Two people on one network count once. Read it as "unique networks".

**CSV export.** `type=clicks` (the default) is one row per click, in the order they were recorded: `time_utc, link, referrer, browser, browser_version, platform, device_type, country`. `type=conversions` is one row per conversion: `time_utc, link, event, value, currency, external_id, source` — without the click token, since whoever holds one can report conversions for that click. No IP address in any form. It opens correctly in Excel (UTF-8 with a byte-order mark), a cell that a spreadsheet would run as a formula (starting with `=`, `+`, `-` or `@`) is defused with a leading `'`, and one export is capped at 100 000 rows — narrow the range for more. It is a plain `GET`, so a read-only key can use it, and it is rate-limited to 10 per minute.

Visitor IP addresses are never stored as they are: they are truncated to a network and hashed first (see the README).

### Custom domains

One domain per site. Getting it live is three checked steps: prove ownership (a DNS `TXT` record), point it at LinkFleet (a `CNAME`), and have a certificate served for it.

| | |
|---|---|
| `GET /api/sites/{site}/domain` | `{ "domain": {...} \| null }` |
| `POST /api/sites/{site}/domain` | `{ "host": "go.example.com" }` — replaces any existing domain |
| `POST /api/domains/{domain}/verify` | Checks the `TXT` record; `422` while it is missing |
| `POST /api/domains/{domain}/check` | `{ target, dns, https }` — is it wired up yet |
| `DELETE /api/domains/{domain}` | |

## Conversions

Clicks say how many people came. Conversions say how many of them did what you wanted — signed up, bought — so you can see which links earn money rather than only which get traffic.

### How it works

1. **Turn it on for a site** (**Сайти → Конверсії**, or `conversion_tracking: true` on the site). From then on, following a short link redirects to your destination with the click's token appended: `https://shop.example/landing?lf_click=Xk3…`. Every click has a token whether or not tracking is on; only the appending is opt-in, because not every destination welcomes an extra parameter. The rest of the URL — parameters, encoding, fragment — is left exactly as you wrote it.
2. **Your site remembers the token** when the visitor lands (the snippet below does it for you).
3. **When the visitor converts, your site reports it**, either from the browser or — for anything involving money — from your server.

A conversion is credited to the click within **90 days**; after that it is refused. Sending the same conversion twice is safe: it is recognised by `(click, event, external_id)` and counted once, so retry freely. A click can lead to several conversions — different events, or the same event with different `external_id`s (a repeat customer's second order). Without an `external_id`, an event counts once per click.

### From the browser

```html
<script>
  window.linkfleet = window.linkfleet || { q: [], track: function () { this.q.push(arguments); } };
</script>
<script async src="https://YOUR-BACKEND/lf.js"></script>
```

The first tag is a stub that queues calls until the script has loaded, so `linkfleet.track` is safe to call at any point. Then, when it happens:

```js
linkfleet.track('signup');
linkfleet.track('purchase', { value: 49.9, currency: 'USD', id: 'order-1001' });
```

The script reads `lf_click` from the address, keeps it in a first-party cookie (`lf_click`, 90 days, this site only, falling back to local storage), and sends each event as a request for a 1×1 image from the server it was loaded from. It is about 1.5 KB compressed, uses no other cookies and no third-party requests, and never throws on your page: if cookies are blocked or the request fails, the conversion is simply not recorded. `linkfleet.clickId()` returns the token it holds. Because the cookie is used to attribute a visit, your cookie notice should mention it.

The pixel can also be called without the script, from an `<img>` on a thank-you page or an email:

```
GET https://YOUR-BACKEND/lf.gif?click=<token>&event=purchase&value=49.90&currency=USD&id=order-1001
```

It always answers with the image — whatever you sent, valid or not — so a page never sees an error, and a stranger cannot use the response to tell a real token from a made-up one. It needs no authentication and is limited to 120 requests a minute per address.

> **Anything sent from a browser can be forged.** Whoever has a click's token can report a conversion for it, including one worth any amount. Conversions that arrive this way are recorded with `source: "pixel"` and the dashboard says so. That is fine for signups; for revenue, report from your server.

### From your server

```bash
curl -X POST https://YOUR-BACKEND/api/conversions \
  -H "Authorization: Bearer lf_..." -H "Content-Type: application/json" \
  -d '{"click_id": "Xk3…", "event": "purchase", "value": 49.90, "currency": "USD", "external_id": "order-1001"}'
```

Needs an [API key](#api-keys) with **write** access (an editor or owner of the workspace). Recorded with `source: "server"`. `201` with the conversion when new, `200` with `"duplicate": true` when it was already recorded.

| Field | |
|---|---|
| `click_id` | required — the `lf_click` value your site received |
| `event` | required — lowercase letters, digits and `. _ : -`, up to 64 characters (`signup`, `purchase`, `trial.started`). Case and stray spaces are tidied, not rejected |
| `value` | optional — a non-negative amount with at most two decimals |
| `currency` | required with `value` — a three-letter code such as `USD`. Amounts are never added across currencies |
| `external_id` | optional — your order or user id, up to 128 characters; makes a resend harmless |

`404` for a token that does not exist **or** belongs to a workspace you cannot see (the two are deliberately indistinguishable), `403` for a viewer, and `422` — with the reason on `click_id` — when the site has tracking off or the click is older than 90 days.

`GET /api/sites/{site}/conversions` lists the latest 50 conversions on a site (event, amount, source, link, time — never the token), which is the quickest way to check an integration works. It is also on the site's **Конверсії** page. Each new conversion is announced by the `conversion.created` [webhook](#webhooks).

## Webhooks

A webhook tells another system when something happens to a link — a new link, an edit, a click — by sending it an HTTP `POST` with a signed JSON body. Set them up per workspace, from the dashboard (**Workspaces → Вебхуки**) or the API below. Only workspace owners can see or change them: an address often carries a token, and the delivery log keeps every payload that was sent.

### Events

| Event | When |
|---|---|
| `link.created` | A link is created — including every row of a CSV import |
| `link.updated` | A link's address, password, expiry or on/off state changes |
| `link.deleted` | A link is deleted. Deleting a whole site or workspace removes its links without announcing each one |
| `link.clicked` | Someone opens a short link. One event per click, so this one can be busy |
| `conversion.created` | A conversion is recorded for one of the workspace's links. Not sent again for a duplicate |
| `ping` | Only from the **Тест** button, to check your endpoint |

Every request is a `POST` with `Content-Type: application/json` and this envelope:

```json
{
  "id": "evt_0b5e9f6a-6d0c-4f6e-a2a1-3c8b1d0f9a11",
  "type": "link.clicked",
  "created_at": "2026-09-30T09:18:42+00:00",
  "workspace_id": 4,
  "data": {
    "link": {
      "id": 71,
      "site_id": 12,
      "short_code": "spring",
      "short_url": "https://go.example.com/spring",
      "target_url": "https://example.com/spring-sale",
      "is_active": true,
      "clicks_count": 1043,
      "expires_at": null,
      "has_password": false,
      "created_at": "2026-09-01T08:00:00+00:00",
      "updated_at": "2026-09-30T09:18:42+00:00"
    },
    "click": {
      "id": "Xk3…",
      "occurred_at": "2026-09-30T09:18:42+00:00",
      "country": "UA",
      "referrer": "twitter.com",
      "browser": "Mobile Safari",
      "browser_version": "17.0",
      "platform": "iOS",
      "device_type": "mobile"
    }
  }
}
```

`data.click` is present only on `link.clicked`, and `data.conversion` only on `conversion.created`:

```json
"conversion": {
  "event": "purchase",
  "value": "49.90",
  "currency": "USD",
  "external_id": "order-1001",
  "source": "server",
  "click_id": "Xk3…",
  "converted_at": "2026-09-30T09:31:07+00:00"
}
```

`click.id` in `link.clicked` is the same token that `conversion.click_id` carries, so a receiver can match a conversion to the click that caused it. The visitor's IP address is never included — not even hashed — the referrer is the host only, and `country` is the two-letter code (or `null`). We may add fields to these objects; we will not rename or remove them.

The same event has the same `id` on every retry and for every webhook that receives it, so use `id` to ignore duplicates.

### Verifying a request

Anyone who learns your endpoint's address can post to it, so check the signature before trusting a request. Each one carries:

```
LinkFleet-Signature: t=1790759636,v1=bc4c3b5b68085c2f6b94ed73ede570bb53b21ac6a198ad759a1b0ba0510efbf2
LinkFleet-Event: link.clicked
LinkFleet-Delivery: evt_0b5e9f6a-6d0c-4f6e-a2a1-3c8b1d0f9a11
```

`v1` is the hex HMAC-SHA256 of `"<t>.<raw request body>"`, keyed with your webhook's secret (`whsec_…`, shown once when you create the webhook or replace its secret). Three rules make it safe:

- Sign the **raw bytes** you received. Parsing the JSON and re-serialising it changes the bytes and the signature will not match.
- Compare in **constant time**.
- Reject a `t` more than a few minutes away from your clock, so a captured request cannot be replayed later.

Return `2xx` as soon as you have verified and stored the event, and do slow work afterwards.

**Node**

```js
import crypto from 'node:crypto';

export function verify(header, rawBody, secret, toleranceSeconds = 300) {
  const parts = Object.fromEntries(header.split(',').map((p) => p.trim().split('=', 2)));
  const { t, v1 } = parts;
  if (!t || !v1 || Math.abs(Date.now() / 1000 - Number(t)) > toleranceSeconds) return false;

  const expected = crypto.createHmac('sha256', secret).update(`${t}.`).update(rawBody).digest('hex');
  const a = Buffer.from(expected);
  const b = Buffer.from(v1);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
```

**PHP**

```php
function verify(string $header, string $rawBody, string $secret, int $tolerance = 300): bool
{
    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[$key] = $value;
    }

    if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])
        || abs(time() - (int) $parts['t']) > $tolerance) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $parts['t'].'.'.$rawBody, $secret), $parts['v1']);
}
```

**Python**

```python
import hashlib, hmac, time

def verify(header: str, raw_body: bytes, secret: str, tolerance: int = 300) -> bool:
    try:
        parts = dict(p.strip().split("=", 1) for p in header.split(","))
        t, v1 = parts["t"], parts["v1"]
        if abs(time.time() - int(t)) > tolerance:
            return False
    except (KeyError, ValueError):
        return False
    expected = hmac.new(secret.encode(), f"{t}.".encode() + raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, v1)
```

### Delivery and retries

- A delivery succeeds on any `2xx`. LinkFleet waits up to 10 seconds for the answer, does **not** follow redirects (a `3xx` counts as a failure), and reads at most 1 MB of the reply.
- A `5xx`, `408`, `429`, a timeout or a refused connection is retried, up to **5 attempts** in total, waiting 10 seconds, 1 minute, 5 minutes and 30 minutes between them.
- Any other `4xx` is final — repeating it would not change the answer. Fix the endpoint and use **Тест**; events that failed for good are not replayed.
- Deliveries are sent by a background worker, never inside the request that caused them, so a slow endpoint cannot slow down a redirect.
- The last 50 attempts per webhook are visible in the dashboard and at `GET /api/webhooks/{webhook}/deliveries`, with the payload, the status, the error and the start of your reply.

A workspace can have up to 10 webhooks.

### Where a webhook may point

Because your endpoint is called *by LinkFleet's server*, addresses that lead into a private network are refused — otherwise a webhook could be aimed at things only the server can reach. That means:

- `https` only, with a hostname or a public IP address — no credentials in the URL;
- nothing that resolves to a private, loopback, link-local or otherwise reserved address (`10.x`, `192.168.x`, `127.x`, `169.254.x`, IPv6 equivalents, `localhost`, `*.internal`, `*.local`). One such answer among several is enough to refuse the name;
- the address is checked when you save it **and again on every delivery**, and the connection goes to the address that was checked, so changing DNS afterwards does not help.

Self-hosting and want to notify a service on your own network? Set `WEBHOOKS_ALLOW_PRIVATE_TARGETS=true`. That lifts the address rule and allows plain `http`; the URL-format rules stay.

### Managing webhooks

Owners only.

| | |
|---|---|
| `GET /api/workspaces/{workspace}/webhooks` | Each webhook, with `latest_delivery` |
| `POST /api/workspaces/{workspace}/webhooks` | `{ "url", "events": [...], "is_active"? }` — the response includes `secret`, **once** |
| `GET` / `PUT` / `DELETE /api/webhooks/{webhook}` | `PUT` takes any of `url`, `events`, `is_active` |
| `POST /api/webhooks/{webhook}/rotate-secret` | New secret, shown once; the old one stops working immediately |
| `POST /api/webhooks/{webhook}/test` | Sends a `ping` now and returns the delivery |
| `GET /api/webhooks/{webhook}/deliveries` | The last 50 attempts, newest first |

## API keys

Managed from a signed-in session only — the dashboard's **API-ключі** page, or these endpoints with a session token.

| | |
|---|---|
| `GET /api/api-keys` | Your keys (never the secret) |
| `POST /api/api-keys` | `{ "name", "access": "read" \| "write", "workspace_id"?: number \| null }` — returns the key **once**, in `token` |
| `DELETE /api/api-keys/{id}` | Revoke |

You can have up to 25 keys.
