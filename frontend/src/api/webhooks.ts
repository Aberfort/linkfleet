import api from './client';
import type { Webhook, WebhookDelivery, WebhookEventName } from '../types';

export interface WebhookPayload {
    url: string;
    events: WebhookEventName[];
    is_active?: boolean;
}

export const listWebhooks = (workspaceId: number) =>
    api.get<Webhook[]>(`/api/workspaces/${workspaceId}/webhooks`).then((r) => r.data);

export const createWebhook = (workspaceId: number, payload: WebhookPayload) =>
    api.post<Webhook>(`/api/workspaces/${workspaceId}/webhooks`, payload).then((r) => r.data);

export const updateWebhook = (id: number, payload: Partial<WebhookPayload>) =>
    api.put<Webhook>(`/api/webhooks/${id}`, payload).then((r) => r.data);

export const deleteWebhook = (id: number) => api.delete(`/api/webhooks/${id}`);

export const rotateWebhookSecret = (id: number) =>
    api.post<Webhook>(`/api/webhooks/${id}/rotate-secret`).then((r) => r.data);

/** Sends a ping right now and returns how it went. */
export const testWebhook = (id: number) =>
    api.post<WebhookDelivery>(`/api/webhooks/${id}/test`).then((r) => r.data);

export const listDeliveries = (id: number) =>
    api.get<WebhookDelivery[]>(`/api/webhooks/${id}/deliveries`).then((r) => r.data);
