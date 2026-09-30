import { describe, it, expect } from 'vitest';
import { formatMoney, formatRate, formatValue } from './format';

// Intl output differs between ICU versions in the spaces it uses (regular,
// non-breaking, narrow), so compare with all of them removed.
const plain = (text: string) => text.replace(/\s/g, '');

describe('formatMoney', () => {
    it('writes an amount in its own currency', () => {
        expect(plain(formatMoney({ amount: 1234.5, currency: 'USD' }))).toContain('1');
        expect(formatMoney({ amount: 1234.5, currency: 'USD' })).toMatch(/USD|\$/);
        expect(formatMoney({ amount: 10, currency: 'EUR' })).toMatch(/EUR|€/);
    });

    it('does not choke on a code the browser does not know', () => {
        expect(plain(formatMoney({ amount: 5, currency: 'XXQ' }))).toContain('5');
        expect(formatMoney({ amount: 5, currency: 'XXQ' })).toContain('XXQ');
    });

    it('does not choke on something that is not a code at all', () => {
        expect(plain(formatMoney({ amount: 5, currency: '??' }))).toBe('5??');
    });
});

describe('formatValue', () => {
    it('shows a dash when there is no money in an event', () => {
        expect(formatValue(null, null)).toBe('—');
    });

    it('formats a stored decimal with its currency', () => {
        expect(formatValue('49.90', 'USD')).toMatch(/49[,.]90/);
    });
});

describe('formatRate', () => {
    it('shows a share of clicks as a percentage', () => {
        expect(plain(formatRate(0.1234))).toBe('12,3%');
        expect(plain(formatRate(1))).toBe('100%');
        expect(plain(formatRate(0))).toBe('0%');
    });

    it('is a dash when there were no clicks to take a share of', () => {
        expect(formatRate(null)).toBe('—');
    });
});
