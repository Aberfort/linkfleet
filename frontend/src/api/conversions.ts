import api from './client';
import type { RecentConversion } from '../types';

/** The latest 50 conversions on a site - what to look at to see whether an integration works. */
export const listRecentConversions = (siteId: number) =>
    api.get<RecentConversion[]>(`/api/sites/${siteId}/conversions`).then((r) => r.data);
