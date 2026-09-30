import api from './client';
import type { Billing, CheckoutOptions, PlanList } from '../types';

export const listPlans = () => api.get<PlanList>('/api/plans').then((r) => r.data);

export const getBilling = (workspaceId: number) =>
    api.get<Billing>(`/api/workspaces/${workspaceId}/billing`).then((r) => r.data);

/** The options Paddle.js opens its checkout with; the server decides who is buying what for whom. */
export const startCheckout = (workspaceId: number, priceId: string) =>
    api
        .post<{ checkout: CheckoutOptions }>(`/api/workspaces/${workspaceId}/billing/checkout`, {
            price_id: priceId,
        })
        .then((r) => r.data.checkout);

export const changePlan = (workspaceId: number, priceId: string) =>
    api
        .post<Billing>(`/api/workspaces/${workspaceId}/billing/change`, { price_id: priceId })
        .then((r) => r.data);

export const cancelSubscription = (workspaceId: number) =>
    api.post<Billing>(`/api/workspaces/${workspaceId}/billing/cancel`).then((r) => r.data);

export const resumeSubscription = (workspaceId: number) =>
    api.post<Billing>(`/api/workspaces/${workspaceId}/billing/resume`).then((r) => r.data);

/** Paddle's hosted page for changing the card on file. */
export const paymentMethodUrl = (workspaceId: number) =>
    api
        .get<{ url: string }>(`/api/workspaces/${workspaceId}/billing/payment-method`)
        .then((r) => r.data.url);
