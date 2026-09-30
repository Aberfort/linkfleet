import type { BillingInterval, Plan } from '../types';

/** The price id a card sells for the chosen interval, or whatever it does sell. */
export function priceFor(plan: Plan, interval: BillingInterval): string | undefined {
    return plan.prices[interval] ?? Object.values(plan.prices)[0];
}
