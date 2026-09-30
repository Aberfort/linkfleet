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

Both return the last 30 days, zero-filled per day:

```json
{
  "timeseries": [{ "date": "2026-09-01", "clicks": 12 }],
  "referrers": [{ "label": "twitter.com", "clicks": 40 }],
  "browsers": [{ "label": "Chrome", "clicks": 90 }],
  "devices": [{ "label": "mobile", "clicks": 70 }]
}
```

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

## API keys

Managed from a signed-in session only — the dashboard's **API-ключі** page, or these endpoints with a session token.

| | |
|---|---|
| `GET /api/api-keys` | Your keys (never the secret) |
| `POST /api/api-keys` | `{ "name", "access": "read" \| "write", "workspace_id"?: number \| null }` — returns the key **once**, in `token` |
| `DELETE /api/api-keys/{id}` | Revoke |

You can have up to 25 keys.
