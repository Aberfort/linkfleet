import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { CheckoutOptions } from '../types';

/**
 * Paddle.js is Paddle's own script, so what is tested here is the seam: that
 * it is loaded once, initialised once, told how to show a checkout, and that
 * its answers are read defensively. The module keeps state between calls, so
 * every test starts from a freshly imported copy.
 */
type Listener = (event: { name: string; data?: unknown }) => void;

interface FakePaddle {
    Environment: { set: ReturnType<typeof vi.fn> };
    Initialize: ReturnType<typeof vi.fn>;
    Checkout: { open: ReturnType<typeof vi.fn> };
    PricePreview: ReturnType<typeof vi.fn>;
}

const config = { client_side_token: 'test_token', sandbox: true };

function fakePaddle(): FakePaddle {
    return {
        Environment: { set: vi.fn() },
        Initialize: vi.fn(),
        Checkout: { open: vi.fn() },
        PricePreview: vi.fn(),
    };
}

/** Stands in for the browser fetching paddle.js: appends nothing, then "loads". */
function interceptScript(outcome: 'load' | 'error', paddle?: FakePaddle) {
    const scripts: HTMLScriptElement[] = [];
    const append = vi.spyOn(document.head, 'appendChild').mockImplementation(((node: Node) => {
        const script = node as HTMLScriptElement;
        scripts.push(script);
        queueMicrotask(() => {
            if (outcome === 'load') {
                window.Paddle = paddle as unknown as Window['Paddle'];
                script.onload?.(new Event('load'));
            } else {
                script.onerror?.(new Event('error'));
            }
        });

        return node;
    }) as typeof document.head.appendChild);

    return { scripts, append };
}

async function freshModule() {
    vi.resetModules();

    return import('./paddle');
}

describe('paddle', () => {
    beforeEach(() => {
        delete window.Paddle;
    });

    afterEach(() => {
        vi.restoreAllMocks();
        delete window.Paddle;
    });

    it('refuses to load without a client-side token', async () => {
        const { loadPaddle } = await freshModule();

        await expect(loadPaddle({ sandbox: true })).rejects.toThrow('Оплата ще не налаштована');
    });

    it('loads Paddles script from Paddles CDN and initialises it once, however often it is asked', async () => {
        const paddle = fakePaddle();
        const { scripts } = interceptScript('load', paddle);
        const { loadPaddle } = await freshModule();

        await Promise.all([loadPaddle(config), loadPaddle(config)]);
        await loadPaddle(config);

        expect(scripts).toHaveLength(1);
        expect(scripts[0].src).toBe('https://cdn.paddle.com/paddle/v2/paddle.js');
        expect(paddle.Initialize).toHaveBeenCalledTimes(1);
        expect(paddle.Initialize.mock.calls[0][0].token).toBe('test_token');
    });

    it('uses the sandbox only when told to', async () => {
        const sandbox = fakePaddle();
        interceptScript('load', sandbox);
        await (await freshModule()).loadPaddle({ client_side_token: 't', sandbox: true });
        expect(sandbox.Environment.set).toHaveBeenCalledWith('sandbox');

        vi.restoreAllMocks();
        delete window.Paddle;
        const live = fakePaddle();
        interceptScript('load', live);
        await (await freshModule()).loadPaddle({ client_side_token: 't', sandbox: false });
        expect(live.Environment.set).not.toHaveBeenCalled();
    });

    it('does not load the script twice when Paddle is already on the page', async () => {
        window.Paddle = fakePaddle() as unknown as Window['Paddle'];
        const append = vi.spyOn(document.head, 'appendChild');
        const { loadPaddle } = await freshModule();

        await loadPaddle(config);

        expect(append).not.toHaveBeenCalled();
    });

    it('can be retried after the script fails to load', async () => {
        interceptScript('error');
        const { loadPaddle } = await freshModule();

        await expect(loadPaddle(config)).rejects.toThrow('Не вдалося завантажити Paddle.js');

        vi.restoreAllMocks();
        const paddle = fakePaddle();
        interceptScript('load', paddle);
        await expect(loadPaddle(config)).resolves.toBeDefined();
        expect(paddle.Initialize).toHaveBeenCalledTimes(1);
    });

    describe('openCheckout', () => {
        const options: CheckoutOptions = {
            settings: { displayMode: 'inline', frameStyle: 'width: 100%', allowLogout: false },
            items: [{ priceId: 'pri_1', quantity: 1 }],
            customer: { id: 'ctm_1' },
            customData: { subscription_type: 'workspace:7' },
        };

        it('shows the servers checkout as an overlay, keeping everything the server decided', async () => {
            const paddle = fakePaddle();
            interceptScript('load', paddle);
            const { openCheckout } = await freshModule();

            await openCheckout(config, options, { onCompleted: vi.fn() });

            const opened = paddle.Checkout.open.mock.calls[0][0];
            expect(opened.settings).toEqual({ allowLogout: false, displayMode: 'overlay' }); // no inline frame style
            expect(opened.items).toEqual(options.items);
            expect(opened.customer).toEqual({ id: 'ctm_1' });
            expect(opened.customData).toEqual({ subscription_type: 'workspace:7' });
        });

        it('reports a completed checkout, and a closed one, to the handlers of the latest checkout', async () => {
            const paddle = fakePaddle();
            interceptScript('load', paddle);
            const { openCheckout } = await freshModule();
            const first = { onCompleted: vi.fn(), onClosed: vi.fn() };
            const second = { onCompleted: vi.fn(), onClosed: vi.fn() };

            await openCheckout(config, options, first);
            await openCheckout(config, options, second);
            const callback = paddle.Initialize.mock.calls[0][0].eventCallback as Listener;

            callback({ name: 'checkout.loaded' });
            callback({ name: 'checkout.completed', data: {} });
            callback({ name: 'checkout.closed' });

            expect(second.onCompleted).toHaveBeenCalledTimes(1);
            expect(second.onClosed).toHaveBeenCalledTimes(1);
            expect(first.onCompleted).not.toHaveBeenCalled(); // an old checkout must not fire again
        });

        it('copes with a closed event when nobody asked for it', async () => {
            const paddle = fakePaddle();
            interceptScript('load', paddle);
            const { openCheckout } = await freshModule();
            await openCheckout(config, options, { onCompleted: vi.fn() });

            const callback = paddle.Initialize.mock.calls[0][0].eventCallback as Listener;

            expect(() => callback({ name: 'checkout.closed' })).not.toThrow();
        });
    });

    describe('previewPrices', () => {
        it('maps each price to what Paddle says it costs this visitor', async () => {
            const paddle = fakePaddle();
            paddle.PricePreview.mockResolvedValue({
                data: {
                    details: {
                        lineItems: [
                            { price: { id: 'pri_a' }, formattedTotals: { total: '$9.00' } },
                            { price: { id: 'pri_b' }, formattedTotals: { total: '€90,00' } },
                        ],
                    },
                },
            });
            interceptScript('load', paddle);
            const { previewPrices } = await freshModule();

            const prices = await previewPrices(config, ['pri_a', 'pri_b']);

            expect(prices).toEqual({ pri_a: '$9.00', pri_b: '€90,00' });
            expect(paddle.PricePreview).toHaveBeenCalledWith({
                items: [
                    { priceId: 'pri_a', quantity: 1 },
                    { priceId: 'pri_b', quantity: 1 },
                ],
            });
        });

        it('skips anything it cannot read instead of failing the page', async () => {
            const paddle = fakePaddle();
            paddle.PricePreview.mockResolvedValue({
                data: { details: { lineItems: [{ price: { id: 'pri_a' } }, { formattedTotals: { total: '$1' } }, { price: { id: 'pri_c' }, formattedTotals: { total: '$3.00' } }] } },
            });
            interceptScript('load', paddle);
            const { previewPrices } = await freshModule();

            expect(await previewPrices(config, ['pri_a', 'pri_c'])).toEqual({ pri_c: '$3.00' });
        });

        it('copes with an answer that is not shaped as expected at all', async () => {
            const paddle = fakePaddle();
            paddle.PricePreview.mockResolvedValue({});
            interceptScript('load', paddle);
            const { previewPrices } = await freshModule();

            expect(await previewPrices(config, ['pri_a'])).toEqual({});
        });

        it('does not even load Paddle when there is nothing to price', async () => {
            const { scripts } = interceptScript('load', fakePaddle());
            const { previewPrices } = await freshModule();

            expect(await previewPrices(config, [])).toEqual({});
            expect(scripts).toHaveLength(0);
        });
    });
});
