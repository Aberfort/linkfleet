import { describe, it, expect } from 'vitest';
import { delta } from './delta';

describe('delta', () => {
    it('shows growth and decline as rounded percentages', () => {
        expect(delta(150, 100)).toEqual({ label: '+50%', direction: 'up' });
        expect(delta(60, 100)).toEqual({ label: '\u221240%', direction: 'down' });
        expect(delta(101, 300)).toEqual({ label: '\u221266%', direction: 'down' });
        expect(delta(2, 3)).toEqual({ label: '\u221233%', direction: 'down' });
    });

    it('calls a change too small to round to a percent flat', () => {
        expect(delta(1001, 1000)).toEqual({ label: '0%', direction: 'flat' });
        expect(delta(5, 5)).toEqual({ label: '0%', direction: 'flat' });
    });

    it('does not divide by zero: growth from nothing is new, nothing to nothing is flat', () => {
        expect(delta(7, 0)).toEqual({ label: 'нове', direction: 'new' });
        expect(delta(0, 0)).toEqual({ label: '0%', direction: 'flat' });
    });

    it('can lose everything', () => {
        expect(delta(0, 40)).toEqual({ label: '\u2212100%', direction: 'down' });
    });
});
