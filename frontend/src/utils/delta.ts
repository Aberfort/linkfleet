export interface Delta {
    /** What to show: "+12%", "−40%", "нове", "0%". */
    label: string;
    direction: 'up' | 'down' | 'flat' | 'new';
}

/**
 * How much `current` moved against `previous`. A percentage of zero is
 * meaningless, so growing from nothing is "нове" rather than an infinity,
 * and nothing against nothing is not a change at all.
 */
export function delta(current: number, previous: number): Delta {
    if (previous === 0) {
        return current === 0 ? { label: '0%', direction: 'flat' } : { label: 'нове', direction: 'new' };
    }

    const percent = Math.round(((current - previous) / previous) * 100);

    if (percent === 0) {
        return { label: '0%', direction: 'flat' };
    }

    return percent > 0
        ? { label: `+${percent}%`, direction: 'up' }
        : { label: `\u2212${Math.abs(percent)}%`, direction: 'down' };
}
