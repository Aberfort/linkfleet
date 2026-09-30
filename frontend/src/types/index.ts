export interface User {
    id: number;
    name: string;
    email: string;
    is_demo: boolean;
}

export type WorkspaceRole = 'owner' | 'editor' | 'viewer';

export interface Workspace {
    id: number;
    name: string;
    /** The signed-in user's role here. */
    role: WorkspaceRole;
    members_count?: number;
    sites_count?: number;
    created_at: string;
    updated_at: string;
}

export interface Member {
    user_id: number;
    name: string;
    email: string;
    role: WorkspaceRole;
    joined_at: string;
}

export type WebhookEventName =
    | 'link.created'
    | 'link.updated'
    | 'link.deleted'
    | 'link.clicked'
    | 'conversion.created';

export interface WebhookDelivery {
    id: number;
    webhook_id: number;
    event: WebhookEventName | 'ping';
    payload: unknown;
    /** Null when nothing came back at all (blocked address, timeout, refused). */
    status_code: number | null;
    success: boolean;
    error: string | null;
    response_excerpt: string | null;
    attempt: number;
    duration_ms: number;
    created_at: string;
}

export interface Webhook {
    id: number;
    workspace_id: number;
    url: string;
    events: WebhookEventName[];
    is_active: boolean;
    created_at: string;
    updated_at: string;
    latest_delivery: Pick<WebhookDelivery, 'success' | 'status_code' | 'event' | 'created_at'> | null;
    /** Present only in the responses to create and rotate-secret - the one time it is shown. */
    secret?: string;
}

export type ApiKeyAccess = 'read' | 'write';

export interface ApiKey {
    id: number;
    name: string;
    access: ApiKeyAccess;
    /** Null when the key spans every workspace of its owner. */
    workspace_id: number | null;
    last_used_at: string | null;
    created_at: string;
}

/** Returned once, on creation - the only time the secret is available. */
export interface CreatedApiKey extends ApiKey {
    token: string;
}

export interface Site {
    id: number;
    workspace_id: number;
    workspace?: { id: number; name: string };
    /** The signed-in user's role in this site's workspace. */
    role: WorkspaceRole;
    name: string;
    domain: string | null;
    description: string | null;
    /** Redirects append ?lf_click=... to the destination so conversions can be traced back. */
    conversion_tracking: boolean;
    links_count?: number;
    created_at: string;
    updated_at: string;
}

export interface Link {
    id: number;
    site_id: number;
    short_code: string;
    target_url: string;
    is_active: boolean;
    clicks_count: number;
    /** ISO timestamp, or null when the link never expires. */
    expires_at: string | null;
    /** The hash itself never leaves the server — this is all the UI gets. */
    has_password: boolean;
    /** What to copy and share: the site's own domain when verified, else /r/{code}. */
    short_url: string;
    created_at: string;
    updated_at: string;
}

export interface Domain {
    id: number;
    site_id: number;
    host: string;
    verification_token: string;
    verified_at: string | null;
    is_verified: boolean;
    /** Where the owner must publish the TXT record. */
    txt_record_name: string;
    created_at: string;
    updated_at: string;
}

export interface ImportResult {
    imported: number;
    skipped: Array<{ row: number; reason: string }>;
    /** The import stopped because the workspace's plan is full. */
    plan_limit: boolean;
}

export interface TimeseriesPoint {
    date: string;
    clicks: number;
}

export interface Breakdown {
    label: string;
    clicks: number;
}

export interface TopLink {
    id: number;
    short_code: string;
    short_url: string;
    target_url: string;
    /** All-time. */
    clicks_count: number;
    /** Within the selected range - what the table is ranked by. */
    period_clicks: number;
    /** Present when the analytics request included conversions (site reports). */
    period_conversions?: number;
    period_revenue?: Money[];
}

/** An amount in one currency. Amounts are never added across currencies. */
export interface Money {
    currency: string;
    amount: number;
}

export interface ConversionSummary {
    total: number;
    /** Share of the range's clicks followed by a conversion, 0..1; null when there were no clicks. */
    rate: number | null;
    revenue: Money[];
}

export interface EventBreakdown {
    event: string;
    conversions: number;
    revenue: Money[];
}

export interface RecentConversion {
    id: number;
    event: string;
    value: string | null;
    currency: string | null;
    external_id: string | null;
    /** 'server' for an authenticated report, 'pixel' for one from a browser (which anyone with the click token can send). */
    source: 'server' | 'pixel';
    link: string;
    created_at: string;
}

export interface DateRange {
    from: string;
    to: string;
    days: number;
}

export interface Totals {
    clicks: number;
    /** Distinct hashed /24 networks, not people - see the tooltip on the dashboard. */
    visitors: number;
}

export interface Analytics {
    range: DateRange;
    totals: Totals;
    timeseries: TimeseriesPoint[];
    referrers: Breakdown[];
    browsers: Breakdown[];
    devices: Breakdown[];
    /** ISO codes as `label`, plus "Unknown". */
    countries: Breakdown[];
    geo: {
        /** This server has a country database, so new clicks are placed. */
        available: boolean;
        /** Credit the data source asks to be shown, if any. */
        attribution: string | null;
    };
    top_links?: TopLink[];
    site_id: number;
    /** Whether the site appends the click token to destinations at all. */
    conversion_tracking: boolean;
    conversions: ConversionSummary & { by_event: EventBreakdown[] };
    /** Present only when the request asked to compare. */
    previous?: {
        range: DateRange;
        totals: Totals;
        timeseries: TimeseriesPoint[];
        conversions: ConversionSummary;
    };
}

/** What the dashboard asks for: a preset of N days, or explicit dates. */
export interface AnalyticsQuery {
    days?: number;
    from?: string;
    to?: string;
    compare?: boolean;
}

export interface BillingConfig {
    /** False on a self-hosted install: no plans, no limits, no billing screens. */
    enabled: boolean;
    /** Public by design - Paddle.js needs it in the browser. Present only when enabled. */
    client_side_token?: string;
    sandbox?: boolean;
}

export interface AppConfig {
    registration_enabled: boolean;
    /** Where a custom domain's DNS should point. */
    custom_domain_target: string;
    billing: BillingConfig;
}

/** What a plan puts a ceiling on. */
export type LimitedResource = 'links' | 'domains' | 'members';

/** A ceiling per resource; null is unlimited. */
export type Limits = Record<LimitedResource, number | null>;

export type Usage = Record<LimitedResource, number>;

export type BillingInterval = 'monthly' | 'yearly';

export interface Plan {
    key: string;
    name: string;
    limits: Limits;
    /** Paddle price ids. Empty for a plan that is not for sale (Free). */
    prices: Partial<Record<BillingInterval, string>>;
}

export interface PlanList {
    plans: Plan[];
    /** False until the server has all its Paddle keys; plans are shown but cannot be bought. */
    checkout_available: boolean;
}

export interface SubscriptionInfo {
    status: string;
    plan: string | null;
    price_id: string | null;
    interval: BillingInterval | null;
    /** When a cancelled subscription stops. */
    ends_at: string | null;
    on_grace_period: boolean;
    past_due: boolean;
    /** The signed-in user is the one paying - only they can change or cancel it. */
    is_payer: boolean;
    payer_name: string | null;
}

export interface Billing {
    plan: { key: string; name: string };
    source: 'self-hosted' | 'free' | 'subscription' | 'granted';
    limits: Limits;
    usage: Usage;
    /** Resources used beyond the current plan (after a downgrade). They keep working. */
    over_limit: LimitedResource[];
    can_manage: boolean;
    subscription: SubscriptionInfo | null;
}

/** The body of a 402: something was refused because the plan is full. */
export interface PlanLimitPayload {
    message: string;
    code: 'plan_limit';
    resource: LimitedResource;
    limit: number;
    usage: number;
    plan: string;
    workspace_id: number;
}

/** What Paddle.Checkout.open takes, as built by the server. */
export interface CheckoutOptions {
    settings?: Record<string, unknown>;
    items: Array<{ priceId: string; quantity: number }>;
    customer?: { id: string };
    customData?: Record<string, unknown>;
}

export interface DomainCheck {
    target: string;
    /** DNS leads to this app. */
    dns: boolean;
    /** A valid certificate answers on the host. */
    https: boolean;
}

export interface ApiErrorPayload {
    message?: string;
    errors?: Record<string, string[]>;
}
