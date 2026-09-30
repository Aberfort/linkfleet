import api from './client';
import type { ApiKey, ApiKeyAccess, CreatedApiKey } from '../types';

export interface ApiKeyPayload {
    name: string;
    access: ApiKeyAccess;
    workspace_id: number | null;
}

export const listApiKeys = () => api.get<ApiKey[]>('/api/api-keys').then((r) => r.data);

export const createApiKey = (payload: ApiKeyPayload) =>
    api.post<CreatedApiKey>('/api/api-keys', payload).then((r) => r.data);

export const revokeApiKey = (id: number) => api.delete(`/api/api-keys/${id}`);
