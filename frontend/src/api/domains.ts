import api from './client';
import type { Domain, DomainCheck } from '../types';

/** Returns null when the site has no domain attached yet. */
export const getDomain = (siteId: number) =>
    api.get<{ domain: Domain | null }>(`/api/sites/${siteId}/domain`).then((r) => r.data.domain);

export const attachDomain = (siteId: number, host: string) =>
    api.post<Domain>(`/api/sites/${siteId}/domain`, { host }).then((r) => r.data);

export const verifyDomain = (domainId: number) =>
    api.post<Domain>(`/api/domains/${domainId}/verify`).then((r) => r.data);

/** Is the verified domain actually wired up yet - DNS first, then a certificate. */
export const checkDomain = (domainId: number) =>
    api.post<DomainCheck>(`/api/domains/${domainId}/check`).then((r) => r.data);

export const detachDomain = (domainId: number) => api.delete(`/api/domains/${domainId}`);
