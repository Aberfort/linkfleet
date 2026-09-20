export interface User {
    id: number;
    name: string;
    email: string;
    is_demo: boolean;
}

export interface Site {
    id: number;
    user_id: number;
    name: string;
    domain: string | null;
    description: string | null;
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
    clicks_count: number;
}

export interface Analytics {
    timeseries: TimeseriesPoint[];
    referrers: Breakdown[];
    browsers: Breakdown[];
    devices: Breakdown[];
    top_links?: TopLink[];
}

export interface AppConfig {
    registration_enabled: boolean;
    /** Where a custom domain's DNS should point. */
    custom_domain_target: string;
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
