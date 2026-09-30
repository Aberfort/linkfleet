import { describe, it, expect } from 'vitest';
import { applyUtm, readUtm, isEditableUrl } from './utm';

describe('readUtm', () => {
    it('reads the five UTM parameters and ignores everything else', () => {
        expect(
            readUtm('https://example.com/p?ref=1&utm_source=news&utm_medium=email&utm_campaign=spring&utm_term=shoes&utm_content=hero')
        ).toEqual({
            utm_source: 'news',
            utm_medium: 'email',
            utm_campaign: 'spring',
            utm_term: 'shoes',
            utm_content: 'hero',
        });
    });

    it('decodes values, treating + as a space like a browser does', () => {
        expect(readUtm('https://example.com/?utm_campaign=summer+sale&utm_source=a%20b%26c')).toEqual({
            utm_campaign: 'summer sale',
            utm_source: 'a b&c',
        });
    });

    it('takes the first of a repeated parameter', () => {
        expect(readUtm('https://example.com/?utm_source=first&utm_source=second')).toEqual({ utm_source: 'first' });
    });

    it('reads nothing from something that is not a web URL', () => {
        for (const url of ['', 'example.com', 'not a url', 'ftp://example.com/?utm_source=x', 'javascript:alert(1)']) {
            expect(readUtm(url)).toEqual({});
        }
    });

    it('does not mistake look-alike names for UTM ones', () => {
        expect(readUtm('https://example.com/?utm_sources=x&UTM_SOURCE=y&xutm_source=z')).toEqual({});
    });
});

describe('applyUtm', () => {
    it('adds parameters to a URL that has no query', () => {
        expect(applyUtm('https://example.com/page', { utm_source: 'news', utm_medium: 'email' })).toBe(
            'https://example.com/page?utm_source=news&utm_medium=email'
        );
    });

    it('appends to an existing query without touching it', () => {
        expect(applyUtm('https://example.com/?a=1&b=two', { utm_source: 'news' })).toBe(
            'https://example.com/?a=1&b=two&utm_source=news'
        );
    });

    it('leaves the targets own encoding byte for byte alone', () => {
        // Round-tripping through URLSearchParams would turn %20 into + and %2C into a comma.
        const url = 'https://example.com/?q=red%20shoes%2C%20size%209&tag=a+b&empty=&flag';

        expect(applyUtm(url, { utm_source: 'news' })).toBe(`${url}&utm_source=news`);
    });

    it('keeps the fragment at the end, where it belongs', () => {
        expect(applyUtm('https://example.com/docs#install', { utm_source: 'news' })).toBe(
            'https://example.com/docs?utm_source=news#install'
        );
        expect(applyUtm('https://example.com/docs?a=1#install', { utm_source: 'news' })).toBe(
            'https://example.com/docs?a=1&utm_source=news#install'
        );
    });

    it('replaces an existing UTM parameter instead of duplicating it', () => {
        expect(applyUtm('https://example.com/?a=1&utm_source=old&b=2', { utm_source: 'new' })).toBe(
            'https://example.com/?a=1&b=2&utm_source=new'
        );
    });

    it('removes a parameter whose value is emptied, and the ? when nothing is left', () => {
        expect(applyUtm('https://example.com/?utm_source=news&a=1', { utm_source: '' })).toBe('https://example.com/?a=1');
        expect(applyUtm('https://example.com/?utm_source=news', {})).toBe('https://example.com/');
        expect(applyUtm('https://example.com/?utm_source=news#top', {})).toBe('https://example.com/#top');
    });

    it('removes every copy of a repeated UTM parameter', () => {
        expect(applyUtm('https://example.com/?utm_source=a&x=1&utm_source=b', { utm_source: 'c' })).toBe(
            'https://example.com/?x=1&utm_source=c'
        );
    });

    it('encodes what it writes, so a value cannot inject another parameter', () => {
        expect(applyUtm('https://example.com/', { utm_campaign: 'a&utm_medium=evil#x' })).toBe(
            'https://example.com/?utm_campaign=a%26utm_medium%3Devil%23x'
        );
        expect(applyUtm('https://example.com/', { utm_campaign: 'summer sale' })).toBe(
            'https://example.com/?utm_campaign=summer%20sale'
        );
        expect(applyUtm('https://example.com/', { utm_campaign: 'Знижка' })).toBe(
            `https://example.com/?utm_campaign=${encodeURIComponent('Знижка')}`
        );
    });

    it('recognises an encoded UTM name when clearing it', () => {
        expect(applyUtm('https://example.com/?utm%5Fsource=old&a=1', { utm_source: 'new' })).toBe(
            'https://example.com/?a=1&utm_source=new'
        );
    });

    it('survives a malformed percent sequence elsewhere in the query', () => {
        expect(applyUtm('https://example.com/?bad=%E0%A4%A&a=1', { utm_source: 'x' })).toBe(
            'https://example.com/?bad=%E0%A4%A&a=1&utm_source=x'
        );
    });

    it('does not edit what is not a web URL', () => {
        for (const url of ['', 'example.com', 'javascript:alert(1)', 'ftp://example.com/x']) {
            expect(applyUtm(url, { utm_source: 'x' })).toBe(url);
        }
    });

    it('is stable: applying what was read changes nothing', () => {
        const url = 'https://example.com/?a=1&utm_source=news&utm_campaign=summer%20sale#top';

        expect(applyUtm(url, readUtm(url))).toBe('https://example.com/?a=1&utm_source=news&utm_campaign=summer%20sale#top');
    });
});

describe('isEditableUrl', () => {
    it('accepts absolute http and https only', () => {
        expect(isEditableUrl('https://example.com')).toBe(true);
        expect(isEditableUrl('http://example.com/x')).toBe(true);
        expect(isEditableUrl('example.com')).toBe(false);
        expect(isEditableUrl('mailto:a@b.c')).toBe(false);
    });
});
