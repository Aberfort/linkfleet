import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
// The snippet is plain ES5 that lives with the backend, which serves it. It
// is tested here because this is where a browser-like environment exists.
import SOURCE from '../../../backend/public/lf.js?raw';

const SCRIPT_SRC = 'https://api.example.test/lf.js';

type Api = {
    track: (event: string, options?: Record<string, unknown>) => boolean;
    clickId: () => string | null;
    q: unknown[];
};

const sent: string[] = [];

/** Runs the snippet the way a browser would when the page includes it. */
function loadSnippet(): Api {
    new Function(SOURCE)();

    return (window as unknown as { linkfleet: Api }).linkfleet;
}

function visit(path: string) {
    window.history.pushState({}, '', path);
}

function forgetEverything() {
    document.cookie = 'lf_click=; Max-Age=0; Path=/';
    window.localStorage.clear();
    delete (window as unknown as { linkfleet?: unknown }).linkfleet;
    document.head.querySelectorAll('script').forEach((s) => s.remove());
    sent.length = 0;
}

describe('lf.js', () => {
    beforeEach(() => {
        forgetEverything();
        const tag = document.createElement('script');
        tag.src = SCRIPT_SRC;
        document.head.appendChild(tag);

        // jsdom would try to fetch a real image; record the address instead.
        vi.stubGlobal(
            'Image',
            class {
                set src(value: string) {
                    sent.push(value);
                }
            }
        );
        visit('/landing');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        forgetEverything();
        visit('/');
    });

    it('remembers the click the visitor arrived from', () => {
        visit('/landing?lf_click=AbC123&utm_source=x');
        const linkfleet = loadSnippet();

        expect(linkfleet.clickId()).toBe('AbC123');
        expect(document.cookie).toContain('lf_click=AbC123');
        expect(window.localStorage.getItem('lf_click')).toBe('AbC123');
    });

    it('still knows it on a later page that has no parameter in the address', () => {
        visit('/landing?lf_click=AbC123');
        loadSnippet();
        delete (window as unknown as { linkfleet?: unknown }).linkfleet;

        visit('/checkout');
        const linkfleet = loadSnippet();

        expect(linkfleet.clickId()).toBe('AbC123');
    });

    it('falls back to local storage when cookies are blocked', () => {
        window.localStorage.setItem('lf_click', 'FromStorage');

        expect(loadSnippet().clickId()).toBe('FromStorage');
    });

    it('a fresh arrival replaces an older click', () => {
        visit('/landing?lf_click=Older');
        loadSnippet();
        delete (window as unknown as { linkfleet?: unknown }).linkfleet;

        visit('/landing?lf_click=Newer');

        expect(loadSnippet().clickId()).toBe('Newer');
    });

    it('sends the event to the server the script came from, with the click', () => {
        visit('/landing?lf_click=AbC123');
        const linkfleet = loadSnippet();

        expect(linkfleet.track('signup')).toBe(true);

        expect(sent).toEqual(['https://api.example.test/lf.gif?click=AbC123&event=signup']);
    });

    it('sends the amount, currency and an id that makes a resend harmless', () => {
        visit('/landing?lf_click=AbC123');
        const linkfleet = loadSnippet();

        linkfleet.track('Purchase', { value: 49.9, currency: 'USD', id: 'order #1001' });

        expect(sent).toEqual([
            'https://api.example.test/lf.gif?click=AbC123&event=purchase&value=49.9&currency=USD&id=order%20%231001',
        ]);
    });

    it('sends no amount parameters for an event that has none', () => {
        visit('/landing?lf_click=AbC123');
        loadSnippet().track('signup', {});

        expect(sent[0]).not.toContain('value=');
        expect(sent[0]).not.toContain('currency=');
    });

    it('sends nothing, and says so, when it does not know the click', () => {
        const linkfleet = loadSnippet();

        expect(linkfleet.clickId()).toBeNull();
        expect(linkfleet.track('signup')).toBe(false);
        expect(sent).toEqual([]);
    });

    it('does not accept a click token that is not one', () => {
        for (const hostile of ['"><script>alert(1)</script>', 'a'.repeat(65), 'has space', 'semi;colon']) {
            forgetEverything();
            visit(`/landing?lf_click=${encodeURIComponent(hostile)}`);
            const linkfleet = loadSnippet();

            expect(linkfleet.clickId(), hostile).toBeNull();
            expect(document.cookie).not.toContain('lf_click=');
            expect(window.localStorage.getItem('lf_click')).toBeNull();
        }
    });

    it('does not send an event whose name would be refused', () => {
        visit('/landing?lf_click=AbC123');
        const linkfleet = loadSnippet();

        for (const name of ['', 'big sale', '-x', 'a'.repeat(65), '<b>']) {
            expect(linkfleet.track(name), name).toBe(false);
        }
        expect(sent).toEqual([]);
    });

    it('will not guess where to send events if it cannot tell where it was loaded from', () => {
        document.head.querySelectorAll('script').forEach((s) => s.remove());
        visit('/landing?lf_click=AbC123');

        expect(loadSnippet().track('signup')).toBe(false);
        expect(sent).toEqual([]);
    });

    it('replays calls made before it finished loading', () => {
        visit('/landing?lf_click=AbC123');
        // The stub the docs tell people to paste ahead of the async script.
        (window as unknown as { linkfleet: unknown }).linkfleet = {
            q: [['signup'], ['purchase', { value: 10, currency: 'EUR', id: 'o1' }]],
        };

        const linkfleet = loadSnippet();

        expect(sent).toEqual([
            'https://api.example.test/lf.gif?click=AbC123&event=signup',
            'https://api.example.test/lf.gif?click=AbC123&event=purchase&value=10&currency=EUR&id=o1',
        ]);
        expect(linkfleet.q).toEqual([]);
    });

    it('never throws on the page it is installed on, whatever the browser does', () => {
        visit('/landing?lf_click=AbC123');
        vi.stubGlobal(
            'Image',
            class {
                set src(_value: string) {
                    throw new Error('blocked by an extension');
                }
            }
        );
        const linkfleet = loadSnippet();

        expect(() => linkfleet.track('signup')).not.toThrow();
        expect(linkfleet.track('signup')).toBe(false);
    });

    it('survives storage that throws', () => {
        visit('/landing?lf_click=AbC123');
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('quota');
        });
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('denied');
        });

        expect(() => loadSnippet()).not.toThrow();
        // The URL still carries it for this very page view.
        expect(window.linkfleet).toBeDefined();
        vi.restoreAllMocks();
    });
});

declare global {
    interface Window {
        linkfleet?: Api;
    }
}
