import api from './client';
import type { Analytics, AnalyticsQuery } from '../types';

export type AnalyticsTarget = { kind: 'site'; id: number } | { kind: 'link'; id: number };

const path = (target: AnalyticsTarget) => `/api/${target.kind}s/${target.id}/analytics`;

/** Only what was chosen is sent, so the server's defaults stay the server's. */
const toParams = (query: AnalyticsQuery) => ({
    ...(query.days ? { days: query.days } : {}),
    ...(query.from ? { from: query.from } : {}),
    ...(query.to ? { to: query.to } : {}),
    ...(query.compare ? { compare: 'previous' } : {}),
});

export const fetchAnalytics = (target: AnalyticsTarget, query: AnalyticsQuery = {}) =>
    api.get<Analytics>(path(target), { params: toParams(query) }).then((r) => r.data);

export const siteAnalytics = (siteId: number, query: AnalyticsQuery = {}) =>
    fetchAnalytics({ kind: 'site', id: siteId }, query);

export const linkAnalytics = (linkId: number, query: AnalyticsQuery = {}) =>
    fetchAnalytics({ kind: 'link', id: linkId }, query);

/**
 * The export is a file behind a bearer token, so it cannot be a plain link:
 * fetch it with the token, then hand the bytes to the browser to save.
 */
export const exportAnalytics = async (target: AnalyticsTarget, query: AnalyticsQuery = {}) => {
    const response = await api.get<Blob>(`${path(target)}/export`, {
        params: toParams({ ...query, compare: false }),
        responseType: 'blob',
    });
    const disposition = String(response.headers['content-disposition'] ?? '');
    const filename = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? 'linkfleet-export.csv';

    return { blob: response.data, filename };
};
