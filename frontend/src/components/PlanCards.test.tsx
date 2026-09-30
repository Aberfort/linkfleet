import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import PlanCards from './PlanCards';
import type { Plan } from '../types';

const plans: Plan[] = [
    { key: 'free', name: 'Free', limits: { links: 25, domains: 0, members: 1 }, prices: {} },
    {
        key: 'pro',
        name: 'Pro',
        limits: { links: 1000, domains: 3, members: 3 },
        prices: { monthly: 'pri_pro_m', yearly: 'pri_pro_y' },
    },
    { key: 'team', name: 'Team', limits: { links: null, domains: 10, members: 15 }, prices: { monthly: 'pri_team_m' } },
];

const prices = { pri_pro_m: '$9.00', pri_pro_y: '$90.00' };

function card(name: string) {
    return screen.getByRole('generic', { name: `Тариф ${name}` });
}

describe('PlanCards', () => {
    it('lists every plan with what it holds', () => {
        render(<PlanCards plans={plans} interval="monthly" prices={prices} action={() => null} />);

        const free = within(card('Free'));
        expect(free.getByText('Безкоштовно')).toBeInTheDocument();
        expect(free.getByText('Посилання').nextSibling).toHaveTextContent('25');
        expect(free.getByText('Власні домени').nextSibling).toHaveTextContent('0');
        expect(free.getByText('Учасники').nextSibling).toHaveTextContent('1');
    });

    it('says unlimited rather than showing a number that is not there', () => {
        render(<PlanCards plans={plans} interval="monthly" prices={prices} action={() => null} />);

        expect(within(card('Team')).getByText('Посилання').nextSibling).toHaveTextContent('Необмежено');
    });

    it('shows the price for the chosen interval', () => {
        const { rerender } = render(<PlanCards plans={plans} interval="monthly" prices={prices} action={() => null} />);
        expect(within(card('Pro')).getByText('$9.00')).toBeInTheDocument();
        expect(within(card('Pro')).getByText(/\/ міс/)).toBeInTheDocument();

        rerender(<PlanCards plans={plans} interval="yearly" prices={prices} action={() => null} />);
        expect(within(card('Pro')).getByText('$90.00')).toBeInTheDocument();
        expect(within(card('Pro')).getByText(/\/ рік/)).toBeInTheDocument();
    });

    it('falls back to the monthly price for a plan with no yearly one', () => {
        render(<PlanCards plans={plans} interval="yearly" prices={{ pri_team_m: '$29.00' }} action={() => null} />);

        expect(within(card('Team')).getByText('$29.00')).toBeInTheDocument();
    });

    it('admits it does not know a price yet instead of showing nothing', () => {
        render(<PlanCards plans={plans} interval="monthly" prices={{}} action={() => null} />);

        expect(within(card('Pro')).getByText('Ціна — під час оплати')).toBeInTheDocument();
        expect(within(card('Free')).getByText('Безкоштовно')).toBeInTheDocument(); // free never needs one
    });

    it('lets the caller put a button on each card, told which price the card sells', () => {
        render(
            <PlanCards
                plans={plans}
                interval="yearly"
                prices={prices}
                action={(plan, priceId) => <button>{`${plan.key}:${priceId ?? '-'}`}</button>}
            />
        );

        expect(within(card('Free')).getByRole('button', { name: 'free:-' })).toBeInTheDocument();
        expect(within(card('Pro')).getByRole('button', { name: 'pro:pri_pro_y' })).toBeInTheDocument();
        expect(within(card('Team')).getByRole('button', { name: 'team:pri_team_m' })).toBeInTheDocument();
    });
});
