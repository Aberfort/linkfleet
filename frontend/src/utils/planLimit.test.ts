import { describe, it, expect } from 'vitest';
import { AxiosError, AxiosHeaders } from 'axios';
import { formatLimit, planLimitOf } from './planLimit';

function failure(status: number, data: unknown): AxiosError {
    return new AxiosError('failed', String(status), undefined, undefined, {
        status,
        statusText: '',
        data,
        headers: {},
        config: { headers: new AxiosHeaders() },
    });
}

const payload = {
    message: 'Ліміт плану «Free» вичерпано — посилань: 25.',
    code: 'plan_limit',
    resource: 'links',
    limit: 25,
    usage: 25,
    plan: 'free',
    workspace_id: 4,
};

describe('planLimitOf', () => {
    it('recognises a "your plan is full" refusal', () => {
        expect(planLimitOf(failure(402, payload))).toEqual(payload);
    });

    it('ignores a 402 that is not about a plan', () => {
        expect(planLimitOf(failure(402, { message: 'Payment required' }))).toBeNull();
    });

    it('ignores every other failure', () => {
        expect(planLimitOf(failure(422, { ...payload }))).toBeNull();
        expect(planLimitOf(failure(403, { message: 'no' }))).toBeNull();
        expect(planLimitOf(new Error('boom'))).toBeNull();
        expect(planLimitOf(undefined)).toBeNull();
    });

    it('survives a response with no body', () => {
        expect(planLimitOf(failure(402, undefined))).toBeNull();
    });
});

describe('formatLimit', () => {
    it('says unlimited for a plan with no ceiling', () => {
        expect(formatLimit(null)).toBe('Необмежено');
    });

    it('groups thousands and keeps zero', () => {
        expect(formatLimit(0)).toBe('0');
        expect(formatLimit(10000).replace(/\s/g, '')).toBe('10000');
        expect(formatLimit(10000)).not.toBe('10000');
    });
});
