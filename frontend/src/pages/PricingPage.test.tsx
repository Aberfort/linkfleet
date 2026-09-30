import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import PricingPage from './PricingPage';
import { useAuth } from '../contexts/useAuth';
import { useConfig } from '../contexts/useConfig';
import * as billingApi from '../api/billing';
import * as paddle from '../utils/paddle';
import type { PlanList } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../contexts/useConfig');
vi.mock('../api/billing');
vi.mock('../utils/paddle');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const catalog: PlanList = {
    checkout_available: true,
    plans: [
        { key: 'free', name: 'Free', limits: { links: 25, domains: 0, members: 1 }, prices: {} },
        { key: 'pro', name: 'Pro', limits: { links: 1000, domains: 3, members: 3 }, prices: { monthly: 'pri_pro_m', yearly: 'pri_pro_y' } },
    ],
};

const config = { loaded: true, registrationEnabled: true, billing: { enabled: true, client_side_token: 'tok', sandbox: true } };

function signedOut() {
    vi.mocked(useAuth).mockReturnValue({ user: null, loading: false, login: vi.fn(), register: vi.fn(), logout: vi.fn() });
}

function renderPage() {
    return render(
        <MemoryRouter initialEntries={['/pricing']}>
            <Routes>
                <Route path="/pricing" element={<PricingPage />} />
                <Route path="/" element={<div>home</div>} />
            </Routes>
        </MemoryRouter>
    );
}

function card(name: string) {
    return within(screen.getByRole('generic', { name: `Тариф ${name}` }));
}

describe('PricingPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        signedOut();
        vi.mocked(useConfig).mockReturnValue(config);
        vi.mocked(billingApi.listPlans).mockResolvedValue(catalog);
        vi.mocked(paddle.previewPrices).mockResolvedValue({});
    });

    it('shows the plans to someone who is not signed in', async () => {
        renderPage();

        expect(await screen.findByRole('generic', { name: 'Тариф Pro' })).toBeInTheDocument();
        expect(card('Free').getByText('Безкоштовно')).toBeInTheDocument();
        expect(card('Pro').getByText('Посилання').nextSibling).toHaveTextContent(/1\s?000/);
    });

    it('sends a visitor to sign up, on either plan', async () => {
        renderPage();
        await screen.findByRole('generic', { name: 'Тариф Pro' });

        expect(card('Free').getByRole('link', { name: 'Почати безкоштовно' })).toHaveAttribute('href', '/register');
        expect(card('Pro').getByRole('link', { name: 'Обрати Pro' })).toHaveAttribute('href', '/register');
    });

    it('sends a visitor to sign in where registration is closed', async () => {
        vi.mocked(useConfig).mockReturnValue({ ...config, registrationEnabled: false });
        renderPage();
        await screen.findByRole('generic', { name: 'Тариф Pro' });

        expect(card('Pro').getByRole('link', { name: 'Обрати Pro' })).toHaveAttribute('href', '/login');
    });

    it('sends someone signed in to their workspaces, where a plan is chosen', async () => {
        vi.mocked(useAuth).mockReturnValue({
            user: { id: 1, name: 'Olga', email: 'o@example.com', is_demo: false },
            loading: false,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
        });
        renderPage();
        await screen.findByRole('generic', { name: 'Тариф Pro' });

        expect(card('Pro').getByRole('link', { name: 'Обрати Pro' })).toHaveAttribute('href', '/workspaces');
        expect(card('Free').getByRole('link', { name: 'Мої workspaces' })).toHaveAttribute('href', '/workspaces');
    });

    it('shows what things cost in the visitors own currency, and follows the interval', async () => {
        vi.mocked(paddle.previewPrices).mockResolvedValue({ pri_pro_m: '€9.00', pri_pro_y: '€90.00' });
        renderPage();
        await screen.findByRole('generic', { name: 'Тариф Pro' });

        expect(await card('Pro').findByText('€9.00')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Щороку' }));

        expect(card('Pro').getByText('€90.00')).toBeInTheDocument();
    });

    it('says when payment is not open yet, and does not ask Paddle for prices', async () => {
        vi.mocked(billingApi.listPlans).mockResolvedValue({ ...catalog, checkout_available: false });
        renderPage();

        expect(await screen.findByText(/Оплата ще не відкрита/)).toBeInTheDocument();
        expect(paddle.previewPrices).not.toHaveBeenCalled();
    });

    it('is not there on an install that sells nothing', async () => {
        vi.mocked(useConfig).mockReturnValue({ ...config, billing: { enabled: false } });
        renderPage();

        expect(await screen.findByText('home')).toBeInTheDocument();
        expect(billingApi.listPlans).not.toHaveBeenCalled();
    });

    it('waits to hear whether anything is sold before deciding', () => {
        vi.mocked(useConfig).mockReturnValue({ ...config, loaded: false, billing: { enabled: false } });
        renderPage();

        expect(screen.queryByText('home')).not.toBeInTheDocument();
    });
});
