import { AxiosError } from 'axios';
import type { LimitedResource, Limits, PlanLimitPayload } from '../types';

/** The details of a "your plan is full" refusal, or null for any other error. */
export function planLimitOf(error: unknown): PlanLimitPayload | null {
    if (error instanceof AxiosError && error.response?.status === 402) {
        const payload = error.response.data as Partial<PlanLimitPayload> | undefined;

        if (payload?.code === 'plan_limit') {
            return payload as PlanLimitPayload;
        }
    }

    return null;
}

export const resourceLabels: Record<LimitedResource, string> = {
    links: 'Посилання',
    domains: 'Власні домени',
    members: 'Учасники',
};

export const resourceOrder: LimitedResource[] = ['links', 'domains', 'members'];

/** "1 000", or "Необмежено" for a plan with no ceiling on it. */
export function formatLimit(limit: Limits[LimitedResource]): string {
    return limit === null ? 'Необмежено' : limit.toLocaleString('uk-UA');
}
