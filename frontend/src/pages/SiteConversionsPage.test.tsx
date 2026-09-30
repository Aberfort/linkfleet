import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import SiteConversionsPage from './SiteConversionsPage';
import { useAuth } from '../contexts/useAuth';
import * as sitesApi from '../api/sites';
import * as conversionsApi from '../api/conversions';
import type { RecentConversion, Site } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../api/sites');
vi.mock('../api/conversions');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const site: Site = {
    id: 1,
    workspace_id: 1,
    workspace: { id: 1, name: 'Acme' },
    role: 'owner',
    name: 'My Shop',
    domain: null,
    description: null,
    conversion_tracking: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

const purchase: RecentConversion = {
    id: 1,
    event: 'purchase',
    value: '49.90',
    currency: 'USD',
    external_id: 'order-1',
    source: 'server',
    link: 'promo',
    created_at: '2026-09-30T10:00:00Z',
};

function signIn(isDemo = false) {
    vi.mocked(useAuth).mockReturnValue({
        user: { id: 1, name: 'User', email: 'u@example.com', is_demo: isDemo },
        loading: false,
        login: vi.fn(),
        register: vi.fn(),
        logout: vi.fn(),
    });
}

function renderPage() {
    return render(
        <MemoryRouter initialEntries={['/sites/1/conversions']}>
            <Routes>
                <Route path="/sites/:siteId/conversions" element={<SiteConversionsPage />} />
            </Routes>
        </MemoryRouter>
    );
}

describe('SiteConversionsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        signIn();
        vi.mocked(sitesApi.getSite).mockResolvedValue(site);
        vi.mocked(conversionsApi.listRecentConversions).mockResolvedValue([]);
    });

    it('shows the snippet, the event calls and the server example once tracking is on', async () => {
        renderPage();

        expect(await screen.findByText(/1\. Підключіть скрипт/)).toBeInTheDocument();
        // Pointed at this deployment's backend, so it can be pasted as it is.
        expect(screen.getByText(/<script async src="[^"]+\/lf\.js"><\/script>/)).toBeInTheDocument();
        expect(screen.getByText(/linkfleet\.track\('purchase'/)).toBeInTheDocument();
        expect(screen.getByText(/\/api\/conversions/)).toBeInTheDocument();
        expect(screen.getByText('Події з браузера можна підробити')).toBeInTheDocument();
    });

    it('offers no instructions while tracking is off, and says why', async () => {
        vi.mocked(sitesApi.getSite).mockResolvedValue({ ...site, conversion_tracking: false });
        renderPage();

        expect(await screen.findByText(/Увімкніть відстеження вище/)).toBeInTheDocument();
        expect(screen.queryByText(/1\. Підключіть скрипт/)).not.toBeInTheDocument();
    });

    it('switches tracking on and reveals the instructions', async () => {
        vi.mocked(sitesApi.getSite).mockResolvedValue({ ...site, conversion_tracking: false });
        vi.mocked(sitesApi.updateSite).mockResolvedValue({ ...site, conversion_tracking: true });
        renderPage();
        await screen.findByText(/Увімкніть відстеження вище/);

        await userEvent.click(screen.getByRole('checkbox', { name: /Відстежувати конверсії/ }));

        await waitFor(() => expect(sitesApi.updateSite).toHaveBeenCalledWith(1, { conversion_tracking: true }));
        expect(await screen.findByText(/1\. Підключіть скрипт/)).toBeInTheDocument();
    });

    it('lists the latest conversions with their amount and where they came from', async () => {
        vi.mocked(conversionsApi.listRecentConversions).mockResolvedValue([
            purchase,
            { ...purchase, id: 2, event: 'signup', value: null, currency: null, source: 'pixel', link: 'docs' },
        ]);
        renderPage();

        // The server example above also says "49.90", so look in the table.
        const table = within(await screen.findByRole('table'));
        expect(table.getByText('purchase')).toBeInTheDocument();
        expect(table.getByText(/49[,.]90/)).toBeInTheDocument();
        expect(table.getByText('сервер')).toBeInTheDocument();
        expect(table.getByText('браузер')).toBeInTheDocument();
        expect(table.getByText('—')).toBeInTheDocument();
    });

    it('says plainly when nothing has arrived yet', async () => {
        renderPage();

        expect(await screen.findByText(/Ще жодної/)).toBeInTheDocument();
    });

    it('refreshes the list on request', async () => {
        renderPage();
        await screen.findByText(/Ще жодної/);
        vi.mocked(conversionsApi.listRecentConversions).mockResolvedValue([purchase]);

        await userEvent.click(screen.getByRole('button', { name: 'Оновити' }));

        expect(within(await screen.findByRole('table')).getByText('purchase')).toBeInTheDocument();
        expect(conversionsApi.listRecentConversions).toHaveBeenCalledTimes(2);
    });

    it('lets a viewer read but not switch anything', async () => {
        vi.mocked(sitesApi.getSite).mockResolvedValue({ ...site, role: 'viewer' });
        renderPage();

        expect(await screen.findByText(/Перемикати відстеження можуть редактори/)).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: /Відстежувати конверсії/ })).toBeDisabled();
    });

    it('is read-only for the demo account', async () => {
        signIn(true);
        renderPage();

        expect(await screen.findByText(/лише для читання/)).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: /Відстежувати конверсії/ })).toBeDisabled();
    });
});
