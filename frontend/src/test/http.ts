import { AxiosError, AxiosHeaders } from 'axios';

/** An error as axios raises it for a non-2xx answer with the given body. */
export function httpFailure(status: number, data: unknown): AxiosError {
    return new AxiosError('failed', String(status), undefined, undefined, {
        status,
        statusText: '',
        data,
        headers: {},
        config: { headers: new AxiosHeaders() },
    });
}

/** The 402 the API answers with when a workspace's plan has no room left. */
export function planFull(workspaceId: number, message = 'Ліміт плану «Free» вичерпано — посилань: 25.'): AxiosError {
    return httpFailure(402, {
        message,
        code: 'plan_limit',
        resource: 'links',
        limit: 25,
        usage: 25,
        plan: 'free',
        workspace_id: workspaceId,
    });
}
