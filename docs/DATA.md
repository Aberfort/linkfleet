# What LinkFleet stores, and who else sees it

This is a plain account of the data the software handles, written from the code. It is the starting point for a privacy policy and a data-processing agreement, **not** either of them and not legal advice: who counts as a controller or a processor for which data, on what legal basis, and for how long, are decisions for whoever runs a deployment and their lawyer. Everything below describes this repository as it is; a deployment that changes the code or its environment should re-check it.

Facts marked **gap** are things a hosted service selling to European customers will be asked about and that the software does not do yet. They are collected at the end.

## People with an account

| Data | Where | Notes |
|---|---|---|
| Name, email | `users` | Typed at registration. Nothing verifies that the email is the person's own |
| Password | `users` | bcrypt hash only |
| Session tokens, API keys | `personal_access_tokens` | Stored as hashes; an API key is shown once, when made. Last-used time is kept |
| Workspace memberships and roles | `user_workspace` | Who belongs to which workspace, and as what |
| Paddle customer (hosted edition) | `customers` | Paddle's customer id, plus the name and email copied from the account |

There are no analytics or tracking scripts in the dashboard, and it sets no cookies: sign-in is a Bearer token kept in the browser's `localStorage`.

## Visitors who follow a link

When someone opens a short link, one row is written to `clicks`:

| Stored | How |
|---|---|
| When | Timestamp |
| Network | The address cut to a **/24** (IPv4) or **/64** (IPv6) prefix, then **HMAC-SHA256** with the app's `APP_KEY`. The raw address is never written to the database. "Unique visitors" therefore counts networks, not people |
| Country | Looked up from the address in a database file on the server ([DB-IP Lite](https://db-ip.com/db/download/ip-to-country-lite), CC BY 4.0) and only the two-letter code kept. No address is sent to anyone for this |
| Referrer | The **host** only (`news.example.com`), never the path or query |
| Browser, version, platform, device type | Parsed locally from the user agent |
| **User agent** | The **full string**, as the browser sent it. **gap:** this is more identifying than the parsed fields above and nothing needs it once they are extracted; decide whether to keep it |
| Click token | A random 24-character id, used to match a later conversion to this click |

What is **not** stored: the raw IP address, the full referrer URL, any cookie or identifier placed on the visitor (the redirect, the password gate, QR codes and the conversion pixel set **no cookies** — a test asserts it), or anything about what the visitor does afterwards on the destination.

Two caveats about the address. For a moment the request carries it, and the country lookup uses it. And the **hosting platform sees it regardless**: its edge and its HTTP logs record visitors' addresses under its own retention rules, which this software cannot change. A branded domain (`go.example.com`) is served by the same platform.

## Conversions (optional, per site)

If a site turns conversion tracking on, its redirects append `?lf_click=<token>` to the destination, so the destination site can report back "this click bought something". A report stores the **event name**, an optional **value and currency**, an optional **external id** and whether it came from a server call or a browser pixel, tied to the click. The external id is whatever the *customer* sends — an order number is fine; an email address would make the customer the one putting personal data here. The token travels to the destination in its URL.

## Links, sites and workspaces

Workspace and site names, descriptions, each link's destination URL and short code, an optional expiry, and an optional link password (bcrypt hash). A destination URL is the customer's data: if it contains personal information in its query string, it is stored as written.

## Webhooks

A workspace's webhook stores the endpoint URL, the chosen events and a signing secret (**encrypted at rest**, since signing needs the original). Each delivery attempt is logged with the full payload sent — which for `link.clicked` includes the click's country, referrer host, browser and device — the response status and up to 1 000 bytes of the response. Older attempts are pruned in passing, so roughly the latest 100 per webhook are kept.

## Payments (hosted edition)

[Paddle](https://www.paddle.com) is the merchant of record: it takes the payment, calculates and pays the VAT, issues the invoice and handles refunds. The checkout is Paddle's, shown over the dashboard; **card details never reach this server**. What is stored here comes from Paddle's webhooks: the Paddle customer id, subscription id, status, the price bought, period dates, and per transaction its id, invoice number, totals, tax and currency (`customers`, `subscriptions`, `subscription_items`, `transactions`).

## Who else receives data

| Recipient | What | Why |
|---|---|---|
| Hosting platform (the reference deployment uses Railway) | Everything above: the application, the database, its logs and backups | Running the service |
| Paddle | Name and email of the paying user; whatever the customer types into Paddle's checkout. Paddle.js, loaded from `cdn.paddle.com` on the pricing and billing pages, sees the visitor's own address to price in their currency | Payments and tax |
| Cloudflare (`cloudflare-dns.com`, configurable) | The domain names customers attach, in DNS-over-HTTPS lookups | Verifying ownership and checking a custom domain's DNS. No visitor data |
| Customers' own webhook endpoints | The payloads above | The customer asked for them |
| The destination of a link | The visitor's address and browser, as with any link, plus the `lf_click` token when conversion tracking is on | It is where the link goes |
| DB-IP | Nothing — the country file is downloaded, not queried | Country lookup |

The dashboard loads no third-party fonts or scripts of its own.

## Retention and deletion

- **Clicks and conversions are kept until the link, site or workspace they belong to is deleted** — deleting cascades to clicks, conversions, the site's domain and the workspace's webhooks. **gap:** there is no automatic retention limit.
- A workspace that is still being billed cannot be deleted until its subscription is cancelled.
- The webhook delivery log prunes itself (above), approximately. Application logs (`storage/logs`) are at `debug` level by default in `.env.example`; production should set `LOG_LEVEL` higher.
- **gap:** there is **no self-service account deletion** and **no full export** of an account's data. The CSV export covers clicks and conversions only.

## Gaps to close before selling to European customers

1. A retention limit for clicks and conversions, configurable per workspace or plan.
2. Account deletion and a full data export — the two rights people will ask for first.
3. Whether to keep the full user agent.
4. Email verification and password reset (accounts can currently be created with any address, and a forgotten password cannot be recovered).
5. Abuse handling. A free, open-registration link shortener is a phishing redirector waiting to happen: it needs a way to report a link, block a destination, and act on either.
6. A data-processing agreement and the published list of sub-processors above.
7. Terms of service, privacy policy and refund policy pages — Paddle will not approve the domain without them.
