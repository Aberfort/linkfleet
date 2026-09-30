const UNKNOWN = 'Невідомо';

let names: Intl.DisplayNames | null | undefined;

function displayNames(): Intl.DisplayNames | null {
    if (names === undefined) {
        try {
            names = new Intl.DisplayNames(['uk'], { type: 'region' });
        } catch {
            names = null;
        }
    }

    return names;
}

/** "UA" -> "🇺🇦": the flag is just the two letters written in regional-indicator characters. */
export function flag(code: string): string {
    return [...code].map((letter) => String.fromCodePoint(0x1f1e6 + letter.charCodeAt(0) - 65)).join('');
}

/**
 * How a breakdown label is shown. The API sends an ISO code ("UA") or the
 * placeholder "Unknown"; anything else is left as it came, so an odd value
 * never turns into a wrong country.
 */
export function countryLabel(label: string): string {
    if (label === 'Unknown') {
        return UNKNOWN;
    }

    if (!/^[A-Z]{2}$/.test(label)) {
        return label;
    }

    let name = label;

    try {
        name = displayNames()?.of(label) ?? label;
    } catch {
        // A code the platform does not know: show it plainly.
    }

    return `${flag(label)} ${name}`;
}
