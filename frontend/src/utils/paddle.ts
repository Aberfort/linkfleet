import type { BillingConfig, CheckoutOptions } from '../types';

/**
 * The little of Paddle.js this app uses. Paddle.js is Paddle's own script: it
 * draws the checkout (card details never touch this app) and prices things in
 * the visitor's currency. It is loaded on demand, only on pages that sell.
 */
interface PaddleEvent {
    name: string;
    data?: unknown;
}

interface PricePreviewResponse {
    data?: {
        details?: {
            lineItems?: Array<{
                price?: { id?: string };
                formattedTotals?: { total?: string };
            }>;
        };
    };
}

interface PaddleGlobal {
    Environment: { set(environment: 'sandbox' | 'production'): void };
    Initialize(options: { token: string; eventCallback?: (event: PaddleEvent) => void }): void;
    Checkout: { open(options: unknown): void };
    PricePreview(request: { items: Array<{ priceId: string; quantity: number }> }): Promise<PricePreviewResponse>;
}

declare global {
    interface Window {
        Paddle?: PaddleGlobal;
    }
}

const SCRIPT_URL = 'https://cdn.paddle.com/paddle/v2/paddle.js';

type PaddleConfig = Pick<BillingConfig, 'client_side_token' | 'sandbox'>;

let ready: Promise<PaddleGlobal> | null = null;
let onEvent: ((event: PaddleEvent) => void) | null = null;

function injectScript(): Promise<void> {
    return new Promise((resolve, reject) => {
        if (window.Paddle) {
            resolve();

            return;
        }

        const script = document.createElement('script');
        script.src = SCRIPT_URL;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Не вдалося завантажити Paddle.js.'));
        document.head.appendChild(script);
    });
}

/** Loads and initialises Paddle.js once; every caller after the first shares it. */
export function loadPaddle(config: PaddleConfig): Promise<PaddleGlobal> {
    if (!config.client_side_token) {
        return Promise.reject(new Error('Оплата ще не налаштована.'));
    }

    const token = config.client_side_token;

    ready ??= injectScript().then(() => {
        const paddle = window.Paddle;

        if (!paddle) {
            throw new Error('Paddle.js не ініціалізувався.');
        }

        if (config.sandbox) {
            paddle.Environment.set('sandbox');
        }

        // One callback for the life of the page; each checkout points it at
        // its own handler (Initialize must only be called once).
        paddle.Initialize({ token, eventCallback: (event) => onEvent?.(event) });

        return paddle;
    });

    // A failed load must be retryable, not remembered forever.
    ready.catch(() => {
        ready = null;
    });

    return ready;
}

/**
 * Opens Paddle's checkout as an overlay. The server built the options (who is
 * paying, for what, for which workspace); the only thing decided here is how
 * it is shown - its default is an inline frame, which this app has no room for.
 */
export async function openCheckout(
    config: PaddleConfig,
    options: CheckoutOptions,
    handlers: { onCompleted: () => void; onClosed?: () => void }
): Promise<void> {
    const paddle = await loadPaddle(config);

    onEvent = (event) => {
        if (event.name === 'checkout.completed') {
            handlers.onCompleted();
        } else if (event.name === 'checkout.closed') {
            handlers.onClosed?.();
        }
    };

    paddle.Checkout.open({
        ...options,
        settings: { allowLogout: false, displayMode: 'overlay' },
    });
}

/**
 * What each price costs this visitor, in their currency, formatted by Paddle
 * (tax included where it applies). Best effort: prices are a nicety, and a
 * page that cannot get them still works.
 */
export async function previewPrices(config: PaddleConfig, priceIds: string[]): Promise<Record<string, string>> {
    if (priceIds.length === 0) {
        return {};
    }

    const paddle = await loadPaddle(config);
    const response = await paddle.PricePreview({ items: priceIds.map((priceId) => ({ priceId, quantity: 1 })) });
    const prices: Record<string, string> = {};

    for (const item of response.data?.details?.lineItems ?? []) {
        const id = item.price?.id;
        const total = item.formattedTotals?.total;

        if (id && total) {
            prices[id] = total;
        }
    }

    return prices;
}
