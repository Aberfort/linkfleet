export const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as const;

export type UtmKey = (typeof UTM_KEYS)[number];
export type UtmValues = Partial<Record<UtmKey, string>>;

const isUtmKey = (name: string): boolean => (UTM_KEYS as readonly string[]).includes(name);

/** A URL we are willing to edit: absolute http(s). Anything else is left exactly as typed. */
export function isEditableUrl(url: string): boolean {
    try {
        const parsed = new URL(url);

        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch {
        return false;
    }
}

/** The UTM parameters currently in a URL. Not a URL yet -> nothing. */
export function readUtm(url: string): UtmValues {
    if (!isEditableUrl(url)) {
        return {};
    }

    const found: UtmValues = {};

    for (const [name, value] of new URL(url).searchParams) {
        // The first occurrence wins, as it does for most analytics tools.
        if (isUtmKey(name) && !(name in found)) {
            found[name as UtmKey] = value;
        }
    }

    return found;
}

/**
 * Sets the given UTM parameters on a URL; an empty value removes that one.
 *
 * Done on the raw query string on purpose. Round-tripping through
 * URLSearchParams would re-encode every parameter the target already has
 * ("%20" becomes "+", "%2C" becomes ","), and a destination that is picky
 * about its own parameters would quietly break. Everything that is not a
 * UTM key comes out byte for byte as it went in, fragment included.
 */
export function applyUtm(url: string, utm: UtmValues): string {
    if (!isEditableUrl(url)) {
        return url;
    }

    const hashAt = url.indexOf('#');
    const fragment = hashAt === -1 ? '' : url.slice(hashAt);
    const withoutFragment = hashAt === -1 ? url : url.slice(0, hashAt);

    const queryAt = withoutFragment.indexOf('?');
    const base = queryAt === -1 ? withoutFragment : withoutFragment.slice(0, queryAt);
    const query = queryAt === -1 ? '' : withoutFragment.slice(queryAt + 1);

    const kept = query
        .split('&')
        .filter((pair) => pair !== '')
        .filter((pair) => {
            const name = pair.split('=')[0];

            try {
                return !isUtmKey(decodeURIComponent(name.replace(/\+/g, ' ')));
            } catch {
                return true;
            }
        });

    const added = UTM_KEYS.filter((key) => (utm[key] ?? '') !== '').map(
        (key) => `${key}=${encodeURIComponent(utm[key] as string)}`
    );

    const merged = [...kept, ...added].join('&');

    return `${base}${merged ? `?${merged}` : ''}${fragment}`;
}
