import type { Money } from '../types';

/** "$1,234.50", "1 234,50 ₴" - the browser knows how each currency is written. Unknown codes are shown plainly. */
export function formatMoney({ amount, currency }: Money): string {
    try {
        return new Intl.NumberFormat('uk-UA', { style: 'currency', currency }).format(amount);
    } catch {
        return `${amount.toLocaleString('uk-UA')} ${currency}`;
    }
}

/** A stored decimal string ("49.90") with its currency; a plain number when there is no money in it. */
export function formatValue(value: string | null, currency: string | null): string {
    if (value === null) {
        return '—';
    }

    return currency ? formatMoney({ amount: Number(value), currency }) : value;
}

/** 0.1234 -> "12,3%". No rate (no clicks) -> "—". */
export function formatRate(rate: number | null): string {
    return rate === null ? '—' : new Intl.NumberFormat('uk-UA', { style: 'percent', maximumFractionDigits: 1 }).format(rate);
}
