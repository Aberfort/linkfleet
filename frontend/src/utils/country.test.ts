import { describe, it, expect } from 'vitest';
import { countryLabel, flag } from './country';

describe('flag', () => {
    it('spells a country code as its flag', () => {
        expect(flag('UA')).toBe('\u{1F1FA}\u{1F1E6}');
        expect(flag('US')).toBe('\u{1F1FA}\u{1F1F8}');
        expect(flag('DE')).toBe('\u{1F1E9}\u{1F1EA}');
    });
});

describe('countryLabel', () => {
    it('shows a known country with its flag and a name', () => {
        const label = countryLabel('UA');

        expect(label.startsWith(flag('UA'))).toBe(true);
        // The name comes from the platform's ICU data, which varies; it must at least be more than the code.
        expect(label.length).toBeGreaterThan(flag('UA').length + 3);
        expect(label).not.toMatch(/^\S+ UA$/);
    });

    it('labels the unknown bucket in Ukrainian', () => {
        expect(countryLabel('Unknown')).toBe('Невідомо');
    });

    it('leaves anything that is not a country code exactly as it came', () => {
        for (const odd of ['ua', 'UKR', 'U1', '', 'Germany', '??']) {
            expect(countryLabel(odd)).toBe(odd);
        }
    });

    it('does not throw for a well-formed code nobody has assigned', () => {
        expect(() => countryLabel('QQ')).not.toThrow();
        expect(countryLabel('QQ')).toContain('QQ');
    });
});
