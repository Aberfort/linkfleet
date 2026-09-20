import type { Link } from '../types';

/**
 * What a table cell should show for a link: just the shared "/r/code" path
 * on the app's own host, or "host/code" once the site has its own domain.
 */
export function shortLabel(link: Pick<Link, 'short_url'>): string {
    const url = new URL(link.short_url);

    return url.pathname.startsWith('/r/') ? url.pathname : `${url.host}${url.pathname}`;
}
