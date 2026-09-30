import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AxiosError, AxiosHeaders } from 'axios';
import { toast } from 'react-toastify';
import BillingPage from './BillingPage';
import { useAuth } from '../contexts/useAuth';
import { useConfig } from '../contexts/useConfig';
import * as billingApi from '../api/billing';
import * as workspacesApi from '../api/workspaces';
import * as paddle from '../utils/paddle';
import type { Billing, PlanList, SubscriptionInfo, Workspace } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../contexts/useConfig');
vi.mock('../api/billing');
vi.mock('../api/workspaces');
vi.mock('../utils/paddle');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warn: vi.fn() } }));

const workspace: Workspace = {
    id: 5,
    name: 'Acme Agency',
    role: 'owner',
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

const catalog: PlanList = {
    checkout_available: true,
    plans: [
        { key: 'free', name: 'Free', limits: { links: 25, domains: 0, members: 1 }, prices: {} },
        { key: 'pro', name: 'Pro', limits: { links: 1000, domains: 3, members: 3 }, prices: { monthly: 'pri_pro_m', yearly: 'pri_pro_y' } },
        { key: 'team', name: 'Team', limits: { links: null, domains: 10, members: 15 }, prices: { monthly: 'pri_team_m', yearly: 'pri_team_y' } },
    ],
};

const free: Billing = {
    plan: { key: 'free', name: 'Free' },
    source: 'free',
    limits: { links: 25, domains: 0, members: 1 },
    usage: { links: 12, domains: 0, members: 1 },
    over_limit: [],
    can_manage: true,
    subscription: null,
};

const subscription: SubscriptionInfo = {
    status: 'active',
    plan: 'pro',
    price_id: 'pri_pro_m',
    interval: 'monthly',
    ends_at: null,
    on_grace_period: false,
    past_due: false,
    is_payer: true,
    payer_name: 'Olga',
};

const pro: Billing = {
    ...free,
    plan: { key: 'pro', name: 'Pro' },
    source: 'subscription',
    limits: { links: 1000, domains: 3, members: 3 },
    subscription,
};

const configValue = {
    loaded: true,
    registrationEnabled: true,
    billing: { enabled: true, client_side_token: 'tok', sandbox: true },
};

function failure(status: number, data: unknown) {
    return new AxiosError('failed', String(status), undefined, undefined, {
        status,
        statusText: '',
        data,
        headers: {},
        config: { headers: new AxiosHeaders() },
    });
}

function signIn(isDemo = false) {
    vi.mocked(useAuth).mockReturnValue({
        user: { id: 1, name: 'Olga', email: 'o@example.com', is_demo: isDemo },
        loading: false,
        login: vi.fn(),
        register: vi.fn(),
        logout: vi.fn(),
    });
}

function renderPage() {
    return render(
        <MemoryRouter initialEntries={['/workspaces/5/billing']}>
            <Routes>
                <Route path="/workspaces/:workspaceId/billing" element={<BillingPage />} />
                <Route path="/workspaces" element={<div>the workspaces list</div>} />
            </Routes>
        </MemoryRouter>
    );
}

function card(name: string) {
    return within(screen.getByRole('generic', { name: `Тариф ${name}` }));
}

describe('BillingPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        signIn();
        vi.mocked(useConfig).mockReturnValue(configValue);
        vi.mocked(workspacesApi.getWorkspace).mockResolvedValue(workspace);
        vi.mocked(billingApi.getBilling).mockResolvedValue(free);
        vi.mocked(billingApi.listPlans).mockResolvedValue(catalog);
        vi.mocked(paddle.previewPrices).mockResolvedValue({});
        vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    describe('reading', () => {
        it('shows the plan, what is used against what it allows, and every plan on offer', async () => {
            renderPage();

            expect(await screen.findByRole('heading', { level: 2, name: 'Free' })).toBeInTheDocument();
            expect(screen.getByText('12 / 25')).toBeInTheDocument();
            expect(screen.getByText('0 / 0')).toBeInTheDocument();
            expect(screen.getByText('1 / 1')).toBeInTheDocument();
            expect(screen.getByText('Acme Agency')).toBeInTheDocument();
            for (const name of ['Free', 'Pro', 'Team']) {
                expect(screen.getByRole('generic', { name: `Тариф ${name}` })).toBeInTheDocument();
            }
        });

        it('shows an infinity sign, and no bar, for what has no ceiling', async () => {
            vi.mocked(billingApi.getBilling).mockResolvedValue({
                ...free,
                plan: { key: 'team', name: 'Team' },
                limits: { links: null, domains: 10, members: 15 },
                usage: { links: 4321, domains: 1, members: 2 },
            });

            renderPage();

            await screen.findByRole('heading', { level: 2, name: 'Team' });
            expect(screen.getByText(/∞/)).toBeInTheDocument();
            expect(screen.getAllByRole('progressbar')).toHaveLength(2); // links has none
        });

        it('draws no bar for something the plan simply does not include', async () => {
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Free' });

            // 12/25 links and 1/1 members have a bar; 0/0 domains is not "full", it is "not part of this plan".
            expect(screen.getAllByRole('progressbar')).toHaveLength(2);
            expect(screen.queryByRole('progressbar', { name: /Власні домени/ })).not.toBeInTheDocument();
        });

        it('does draw one for domains that exist on a plan that has none', async () => {
            vi.mocked(billingApi.getBilling).mockResolvedValue({
                ...free,
                usage: { links: 12, domains: 2, members: 1 },
                over_limit: ['domains'],
            });

            renderPage();

            expect(await screen.findByRole('progressbar', { name: /Власні домени: використано 2 з 0/ })).toBeInTheDocument();
        });

        it('marks the current plan and offers the others', async () => {
            renderPage();

            await screen.findByRole('heading', { level: 2, name: 'Free' });
            expect(card('Free').getByText('Поточний тариф')).toBeInTheDocument();
            expect(card('Pro').getByRole('button', { name: 'Обрати Pro' })).toBeEnabled();
            expect(card('Team').getByRole('button', { name: 'Обрати Team' })).toBeEnabled();
        });

        it('shows prices Paddle gives for this visitor', async () => {
            vi.mocked(paddle.previewPrices).mockResolvedValue({ pri_pro_m: '€9.00', pri_team_m: '€29.00' });

            renderPage();

            expect(await within(await screen.findByRole('generic', { name: 'Тариф Pro' })).findByText('€9.00')).toBeInTheDocument();
            expect(card('Team').getByText('€29.00')).toBeInTheDocument();
            expect(paddle.previewPrices).toHaveBeenCalledWith(
                configValue.billing,
                ['pri_pro_m', 'pri_pro_y', 'pri_team_m', 'pri_team_y']
            );
        });

        it('still works when Paddle will not say what things cost', async () => {
            vi.mocked(paddle.previewPrices).mockRejectedValue(new Error('blocked by an extension'));

            renderPage();

            await screen.findByRole('heading', { level: 2, name: 'Free' });
            expect(card('Pro').getByText('Ціна — під час оплати')).toBeInTheDocument();
        });

        it('warns when the workspace has more than its plan allows, and says nothing is lost', async () => {
            vi.mocked(billingApi.getBilling).mockResolvedValue({
                ...free,
                usage: { links: 40, domains: 0, members: 1 },
                over_limit: ['links'],
            });

            renderPage();

            expect(await screen.findByText('Ліміт тарифу перевищено')).toBeInTheDocument();
            expect(screen.getByText(/Усе наявне продовжує працювати/)).toBeInTheDocument();
        });

        it('goes away from a server that sells nothing', async () => {
            vi.mocked(useConfig).mockReturnValue({ ...configValue, billing: { enabled: false } });

            renderPage();

            expect(await screen.findByText('the workspaces list')).toBeInTheDocument();
            expect(billingApi.getBilling).not.toHaveBeenCalled();
        });

        it('does not leave before it knows whether anything is sold', async () => {
            vi.mocked(useConfig).mockReturnValue({ ...configValue, loaded: false, billing: { enabled: false } });

            renderPage();

            expect(screen.queryByText('the workspaces list')).not.toBeInTheDocument();
        });

        it('says so when the server cannot take payments yet', async () => {
            vi.mocked(billingApi.listPlans).mockResolvedValue({ ...catalog, checkout_available: false });

            renderPage();

            expect(await screen.findByText(/Оплата ще не налаштована/)).toBeInTheDocument();
            expect(card('Pro').getByRole('button', { name: 'Обрати Pro' })).toBeDisabled();
            expect(paddle.previewPrices).not.toHaveBeenCalled();
        });

        it('shows the reason when it cannot load at all', async () => {
            vi.mocked(billingApi.getBilling).mockRejectedValue(failure(403, { message: 'Немає доступу' }));

            renderPage();

            await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Немає доступу'));
        });
    });

    describe('who may buy', () => {
        it('gives a member who is not an owner nothing to click', async () => {
            vi.mocked(billingApi.getBilling).mockResolvedValue({ ...free, can_manage: false });

            renderPage();

            expect(await screen.findByText('Змінювати тариф може лише власник workspace.')).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /Обрати/ })).not.toBeInTheDocument();
        });

        it('gives the read-only demo nothing to click either', async () => {
            signIn(true);

            renderPage();

            expect(await screen.findByText(/Демо-акаунт лише для читання/)).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /Обрати/ })).not.toBeInTheDocument();
        });
    });

    describe('buying', () => {
        it('asks the server for the checkout and opens it, for the price on the card', async () => {
            const options = { items: [{ priceId: 'pri_pro_m', quantity: 1 }], customer: { id: 'ctm_1' } };
            vi.mocked(billingApi.startCheckout).mockResolvedValue(options);
            vi.mocked(paddle.openCheckout).mockResolvedValue();
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Free' });

            await userEvent.click(card('Pro').getByRole('button', { name: 'Обрати Pro' }));

            await waitFor(() => expect(paddle.openCheckout).toHaveBeenCalled());
            expect(billingApi.startCheckout).toHaveBeenCalledWith(5, 'pri_pro_m');
            expect(vi.mocked(paddle.openCheckout).mock.calls[0][0]).toEqual(configValue.billing);
            expect(vi.mocked(paddle.openCheckout).mock.calls[0][1]).toEqual(options);
        });

        it('buys the yearly price once yearly is chosen', async () => {
            vi.mocked(billingApi.startCheckout).mockResolvedValue({ items: [] });
            vi.mocked(paddle.openCheckout).mockResolvedValue();
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Free' });

            await userEvent.click(screen.getByRole('button', { name: 'Щороку' }));
            await userEvent.click(card('Team').getByRole('button', { name: 'Обрати Team' }));

            await waitFor(() => expect(billingApi.startCheckout).toHaveBeenCalledWith(5, 'pri_team_y'));
        });

        it('offers no interval switch when nothing is sold yearly', async () => {
            vi.mocked(billingApi.listPlans).mockResolvedValue({
                ...catalog,
                plans: catalog.plans.map((p) => ({ ...p, prices: p.prices.monthly ? { monthly: p.prices.monthly } : {} })),
            });

            renderPage();

            await screen.findByRole('heading', { level: 2, name: 'Free' });
            expect(screen.queryByRole('button', { name: 'Щороку' })).not.toBeInTheDocument();
        });

        it('waits for Paddle to tell the server, then shows the new plan', async () => {
            vi.mocked(billingApi.startCheckout).mockResolvedValue({ items: [] });
            vi.mocked(billingApi.getBilling).mockResolvedValueOnce(free).mockResolvedValue(pro);
            vi.mocked(paddle.openCheckout).mockImplementation(async (_config, _options, handlers) => {
                // The customer pays; Paddle.js says so.
                setTimeout(() => handlers.onCompleted(), 0);
            });
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Free' });

            await userEvent.click(card('Pro').getByRole('button', { name: 'Обрати Pro' }));

            expect(await screen.findByRole('heading', { level: 2, name: 'Pro' })).toBeInTheDocument();
            expect(toast.success).toHaveBeenCalledWith('Тариф підключено.');
        });

        it('asks the customer to check back if the plan has not appeared after a while', async () => {
            vi.mocked(billingApi.startCheckout).mockResolvedValue({ items: [] });
            let completed: () => void = () => undefined;
            vi.mocked(paddle.openCheckout).mockImplementation(async (_c, _o, handlers) => {
                completed = handlers.onCompleted;
            });
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Free' });
            await userEvent.click(card('Pro').getByRole('button', { name: 'Обрати Pro' }));
            await waitFor(() => expect(paddle.openCheckout).toHaveBeenCalled());

            vi.useFakeTimers();
            await act(async () => {
                completed();
                await vi.advanceTimersByTimeAsync(2000 * 16);
            });

            expect(toast.info).toHaveBeenCalledWith(expect.stringContaining('Оплату отримано'));
            expect(toast.success).not.toHaveBeenCalled();
        });

        it('says why when the checkout could not be started, and opens nothing', async () => {
            vi.mocked(billingApi.startCheckout).mockRejectedValue(failure(502, { message: 'Не вдалося зв’язатися з Paddle.' }));
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Free' });

            await userEvent.click(card('Pro').getByRole('button', { name: 'Обрати Pro' }));

            await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Не вдалося зв’язатися з Paddle.'));
            expect(paddle.openCheckout).not.toHaveBeenCalled();
        });

        it('says why when Paddle.js itself would not load', async () => {
            vi.mocked(billingApi.startCheckout).mockResolvedValue({ items: [] });
            vi.mocked(paddle.openCheckout).mockRejectedValue(new Error('blocked'));
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Free' });

            await userEvent.click(card('Pro').getByRole('button', { name: 'Обрати Pro' }));

            await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Не вдалося відкрити оплату.'));
        });
    });

    describe('with a subscription', () => {
        beforeEach(() => {
            vi.mocked(billingApi.getBilling).mockResolvedValue(pro);
        });

        it('offers to move to another plan, not to buy one', async () => {
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Pro' });

            expect(card('Pro').getByText('Поточний тариф')).toBeInTheDocument();
            expect(card('Team').getByRole('button', { name: 'Перейти на Team' })).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /Обрати/ })).not.toBeInTheDocument();
        });

        it('changes plan after confirming, and shows the result', async () => {
            vi.mocked(billingApi.changePlan).mockResolvedValue({
                ...pro,
                plan: { key: 'team', name: 'Team' },
                limits: { links: null, domains: 10, members: 15 },
                subscription: { ...subscription, plan: 'team', price_id: 'pri_team_m' },
            });
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Pro' });

            await userEvent.click(card('Team').getByRole('button', { name: 'Перейти на Team' }));

            expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('Team'));
            expect(billingApi.changePlan).toHaveBeenCalledWith(5, 'pri_team_m');
            expect(await screen.findByRole('heading', { level: 2, name: 'Team' })).toBeInTheDocument();
        });

        it('does nothing if the customer thinks better of it', async () => {
            vi.mocked(window.confirm).mockReturnValue(false);
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Pro' });

            await userEvent.click(card('Team').getByRole('button', { name: 'Перейти на Team' }));
            await userEvent.click(card('Free').getByRole('button', { name: 'Скасувати підписку' }));

            expect(billingApi.changePlan).not.toHaveBeenCalled();
            expect(billingApi.cancelSubscription).not.toHaveBeenCalled();
        });

        it('cancels through the free plan, and says until when the plan lasts', async () => {
            const ends = '2026-12-01T00:00:00Z';
            vi.mocked(billingApi.cancelSubscription).mockResolvedValue({
                ...pro,
                subscription: { ...subscription, on_grace_period: true, ends_at: ends },
            });
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Pro' });

            await userEvent.click(card('Free').getByRole('button', { name: 'Скасувати підписку' }));

            expect(billingApi.cancelSubscription).toHaveBeenCalledWith(5);
            expect(await screen.findByText(/Підписку скасовано\. Тариф Pro діє до/)).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: 'Скасувати підписку' })).not.toBeInTheDocument();
        });

        it('lets a cancellation be taken back', async () => {
            vi.mocked(billingApi.getBilling).mockResolvedValue({
                ...pro,
                subscription: { ...subscription, on_grace_period: true, ends_at: '2026-12-01T00:00:00Z' },
            });
            vi.mocked(billingApi.resumeSubscription).mockResolvedValue(pro);
            renderPage();

            await userEvent.click(await screen.findByRole('button', { name: 'Не скасовувати' }));

            expect(billingApi.resumeSubscription).toHaveBeenCalledWith(5);
            await waitFor(() => expect(screen.queryByText(/Підписку скасовано\./)).not.toBeInTheDocument());
        });

        it('warns about a failed payment and offers to fix the card, but no plan change meanwhile', async () => {
            vi.mocked(billingApi.getBilling).mockResolvedValue({ ...pro, subscription: { ...subscription, status: 'past_due', past_due: true } });
            vi.mocked(billingApi.paymentMethodUrl).mockResolvedValue('https://checkout.paddle.test/update');
            const open = vi.spyOn(window, 'open').mockReturnValue(null);
            renderPage();

            expect(await screen.findByText('Останній платіж не пройшов')).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /Перейти на/ })).not.toBeInTheDocument();

            await userEvent.click(screen.getByRole('button', { name: 'Оновити картку' }));

            await waitFor(() => expect(open).toHaveBeenCalledWith('https://checkout.paddle.test/update', '_blank', 'noopener'));
        });

        it('opens the page for changing the card on request', async () => {
            vi.mocked(billingApi.paymentMethodUrl).mockResolvedValue('https://checkout.paddle.test/update');
            const open = vi.spyOn(window, 'open').mockReturnValue(null);
            renderPage();

            await userEvent.click(await screen.findByRole('button', { name: 'Змінити спосіб оплати' }));

            await waitFor(() => expect(open).toHaveBeenCalledWith('https://checkout.paddle.test/update', '_blank', 'noopener'));
        });

        it('shows an owner who is not the payer whose subscription it is, and nothing to change it with', async () => {
            vi.mocked(billingApi.getBilling).mockResolvedValue({ ...pro, subscription: { ...subscription, is_payer: false, payer_name: 'Ivan' } });

            renderPage();

            expect(await screen.findByText(/Підписку оплачує Ivan/)).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /Перейти на/ })).not.toBeInTheDocument();
            expect(screen.queryByRole('button', { name: 'Скасувати підписку' })).not.toBeInTheDocument();
            expect(screen.queryByRole('button', { name: 'Змінити спосіб оплати' })).not.toBeInTheDocument();
        });

        it('says when the server refused a change', async () => {
            vi.mocked(billingApi.changePlan).mockRejectedValue(failure(409, { message: 'Спершу оновіть спосіб оплати' }));
            renderPage();
            await screen.findByRole('heading', { level: 2, name: 'Pro' });

            await userEvent.click(card('Team').getByRole('button', { name: 'Перейти на Team' }));

            await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Спершу оновіть спосіб оплати'));
        });

        it('names a granted plan as a gift, not a subscription', async () => {
            vi.mocked(billingApi.getBilling).mockResolvedValue({ ...free, plan: { key: 'team', name: 'Team' }, source: 'granted', limits: { links: null, domains: 10, members: 15 } });

            renderPage();

            expect(await screen.findByText('Тариф надано адміністратором.')).toBeInTheDocument();
        });
    });
});
