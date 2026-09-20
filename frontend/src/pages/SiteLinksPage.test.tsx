import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import SiteLinksPage from './SiteLinksPage';
import { useAuth } from '../contexts/useAuth';
import * as sitesApi from '../api/sites';
import * as linksApi from '../api/links';
import type { Site, Link } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../api/sites');
vi.mock('../api/links');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const site: Site = {
    id: 1,
    user_id: 1,
    name: 'My Site',
    domain: 'example.com',
    description: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

const link: Link = {
    id: 10,
    site_id: 1,
    short_code: 'promo',
    target_url: 'https://example.com/promo',
    is_active: true,
    clicks_count: 42,
    expires_at: null,
    has_password: false,
    short_url: 'https://api.example.test/r/promo',
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

function renderPage() {
    return render(
        <MemoryRouter initialEntries={['/sites/1/links']}>
            <Routes>
                <Route path="/sites/:siteId/links" element={<SiteLinksPage />} />
            </Routes>
        </MemoryRouter>
    );
}

describe('SiteLinksPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(sitesApi.getSite).mockResolvedValue(site);
        vi.mocked(useAuth).mockReturnValue({
            user: { id: 1, name: 'User', email: 'u@example.com', is_demo: false },
            loading: false,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
        });
    });

    it('lists links for the site', async () => {
        vi.mocked(linksApi.listLinks).mockResolvedValue([link]);

        renderPage();

        expect(await screen.findByText('/r/promo')).toBeInTheDocument();
        expect(screen.getByText('https://example.com/promo')).toBeInTheDocument();
        expect(screen.getByText('42')).toBeInTheDocument();
    });

    it('toggles a link active state', async () => {
        vi.mocked(linksApi.listLinks).mockResolvedValue([link]);
        vi.mocked(linksApi.toggleLink).mockResolvedValue({ ...link, is_active: false });

        renderPage();
        await screen.findByText('/r/promo');

        await userEvent.click(screen.getByRole('checkbox'));

        await waitFor(() => expect(linksApi.toggleLink).toHaveBeenCalledWith(link.id));
    });

    it('renders a read-only status chip instead of a toggle for the demo account', async () => {
        vi.mocked(useAuth).mockReturnValue({
            user: { id: 1, name: 'Demo', email: 'demo@linkfleet.app', is_demo: true },
            loading: false,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
        });
        vi.mocked(linksApi.listLinks).mockResolvedValue([link]);

        renderPage();
        await screen.findByText('/r/promo');

        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.getByText('Активне', { selector: 'span' })).toBeInTheDocument();
    });

    it('opens a QR dialog pointing at the public QR endpoint', async () => {
        vi.mocked(linksApi.listLinks).mockResolvedValue([link]);

        renderPage();
        await screen.findByText('/r/promo');

        await userEvent.click(screen.getByRole('button', { name: 'QR-код' }));

        const image = await screen.findByAltText('QR-код для /r/promo');
        expect(image).toHaveAttribute('src', expect.stringContaining('/qr/promo.svg'));
    });

    it('shows and copies the branded address once the site has a verified domain', async () => {
        const branded: Link = { ...link, short_url: 'https://go.example.com/promo' };
        vi.mocked(linksApi.listLinks).mockResolvedValue([branded]);
        // jsdom ships no clipboard at all, so there is nothing to spy on.
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

        renderPage();

        expect(await screen.findByText('go.example.com/promo')).toBeInTheDocument();
        expect(screen.queryByText('/r/promo')).not.toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Копіювати' }));

        expect(writeText).toHaveBeenCalledWith('https://go.example.com/promo');
    });

    it('uploads a chosen CSV file and refreshes the list', async () => {
        vi.mocked(linksApi.listLinks).mockResolvedValue([link]);
        vi.mocked(linksApi.importLinks).mockResolvedValue({ imported: 2, skipped: [] });

        renderPage();
        await screen.findByText('/r/promo');

        const file = new File(['target_url\nhttps://example.com/x\n'], 'links.csv', { type: 'text/csv' });
        await userEvent.upload(screen.getByTestId('import-input'), file);

        await waitFor(() => expect(linksApi.importLinks).toHaveBeenCalledWith(1, file));
        // The list is re-fetched so freshly imported links show up.
        await waitFor(() => expect(linksApi.listLinks).toHaveBeenCalledTimes(2));
    });

    it('marks a password-protected, expired link in the table', async () => {
        vi.mocked(linksApi.listLinks).mockResolvedValue([
            { ...link, has_password: true, expires_at: '2020-01-01T00:00:00Z' },
        ]);

        renderPage();
        await screen.findByText('/r/promo');

        expect(await screen.findByLabelText('Захищене паролем')).toBeInTheDocument();
        expect(screen.getByLabelText(/Термін дії минув/)).toBeInTheDocument();
    });
});
