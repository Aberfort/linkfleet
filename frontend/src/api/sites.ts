import api from './client';
import type { Site } from '../types';

export interface SitePayload {
    /** Required when creating; a site never moves between workspaces. */
    workspace_id?: number;
    name: string;
    domain?: string;
    description?: string;
    conversion_tracking?: boolean;
}

export const listSites = () => api.get<Site[]>('/api/sites').then((r) => r.data);

export const getSite = (id: number) => api.get<Site>(`/api/sites/${id}`).then((r) => r.data);

export const createSite = (payload: SitePayload) =>
    api.post<Site>('/api/sites', payload).then((r) => r.data);

export const updateSite = (id: number, payload: Partial<SitePayload>) =>
    api.put<Site>(`/api/sites/${id}`, payload).then((r) => r.data);

export const deleteSite = (id: number) => api.delete(`/api/sites/${id}`);
