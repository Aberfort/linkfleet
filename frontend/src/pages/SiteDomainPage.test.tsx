import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import SiteDomainPage from './SiteDomainPage';
import { useAuth } from '../contexts/useAuth';
import * as sitesApi from '../api/sites';
import * as domainsApi from '../api/domains';
import * as configApi from '../api/config';
import type { Domain, Site } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../api/sites');
vi.mock('../api/domains');
vi.mock('../api/config');
vi.mock('react-toastify', () => ({
    toast: { success: vi.fn(), error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));

const site: Site = {
    id: 1,
    user_id: 1,
    name: 'My Site',
    domain: 'example.com',
    description: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

const pending: Domain = {
    id: 5,
    site_id: 1,
    host: 'go.example.com',
    verification_token: 'tok_abc123',
    verified_at: null,
    is_verified: false,
    txt_record_name: '_linkfleet.go.example.com',
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

function renderPage() {
    return render(
        <MemoryRouter initialEntries={['/sites/1/domain']}>
            <Routes>
                <Route path="/sites/:siteId/domain" element={<SiteDomainPage />} />
            </Routes>
        </MemoryRouter>
    );
}

describe('SiteDomainPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(sitesApi.getSite).mockResolvedValue(site);
        vi.mocked(configApi.fetchAppConfig).mockResolvedValue({
            registration_enabled: true,
            custom_domain_target: 'edge.example.net',
        });
        vi.mocked(useAuth).mockReturnValue({
            user: { id: 1, name: 'User', email: 'u@example.com', is_demo: false },
            loading: false,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
        });
    });

    it('offers a form when no domain is attached', async () => {
        vi.mocked(domainsApi.getDomain).mockResolvedValue(null);

        renderPage();

        expect(await screen.findByLabelText('Домен')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Додати домен' })).toBeInTheDocument();
    });

    it('attaches a domain and then shows the TXT record to publish', async () => {
        vi.mocked(domainsApi.getDomain).mockResolvedValue(null);
        vi.mocked(domainsApi.attachDomain).mockResolvedValue(pending);

        renderPage();
        await userEvent.type(await screen.findByLabelText('Домен'), 'go.example.com');
        await userEvent.click(screen.getByRole('button', { name: 'Додати домен' }));

        await waitFor(() => expect(domainsApi.attachDomain).toHaveBeenCalledWith(1, 'go.example.com'));
        expect(await screen.findByText('_linkfleet.go.example.com')).toBeInTheDocument();
        expect(screen.getByText('tok_abc123')).toBeInTheDocument();
    });

    it('verifies a pending domain', async () => {
        vi.mocked(domainsApi.getDomain).mockResolvedValue(pending);
        vi.mocked(domainsApi.verifyDomain).mockResolvedValue({
            ...pending,
            is_verified: true,
            verified_at: '2026-01-02T00:00:00Z',
        });

        renderPage();
        await userEvent.click(await screen.findByRole('button', { name: 'Перевірити' }));

        await waitFor(() => expect(domainsApi.verifyDomain).toHaveBeenCalledWith(5));
        expect(await screen.findByText('Підтверджено')).toBeInTheDocument();
    });

    const verified: Domain = { ...pending, is_verified: true, verified_at: '2026-01-02T00:00:00Z' };

    it('tells a verified domain where to point its DNS', async () => {
        vi.mocked(domainsApi.getDomain).mockResolvedValue(verified);

        renderPage();

        expect(await screen.findByText('CNAME')).toBeInTheDocument();
        expect(screen.getByText('edge.example.net')).toBeInTheDocument();
        expect(screen.queryByText('tok_abc123')).not.toBeInTheDocument();
    });

    it('reports the domain as working once DNS and HTTPS both check out', async () => {
        vi.mocked(domainsApi.getDomain).mockResolvedValue(verified);
        vi.mocked(domainsApi.checkDomain).mockResolvedValue({ target: 'edge.example.net', dns: true, https: true });

        renderPage();
        await userEvent.click(await screen.findByRole('button', { name: 'Перевірити підключення' }));

        expect(await screen.findByText('Домен працює')).toBeInTheDocument();
        expect(domainsApi.checkDomain).toHaveBeenCalledWith(5);
    });

    it('says the certificate is pending when DNS is right but HTTPS is not up yet', async () => {
        vi.mocked(domainsApi.getDomain).mockResolvedValue(verified);
        vi.mocked(domainsApi.checkDomain).mockResolvedValue({ target: 'edge.example.net', dns: true, https: false });

        renderPage();
        await userEvent.click(await screen.findByRole('button', { name: 'Перевірити підключення' }));

        expect(await screen.findByText('DNS уже вказує сюди, сертифікат ще готується')).toBeInTheDocument();
    });

    it('names the missing DNS target when the domain does not point here yet', async () => {
        vi.mocked(domainsApi.getDomain).mockResolvedValue(verified);
        vi.mocked(domainsApi.checkDomain).mockResolvedValue({ target: 'edge.example.net', dns: false, https: false });

        renderPage();
        await userEvent.click(await screen.findByRole('button', { name: 'Перевірити підключення' }));

        expect(await screen.findByText('DNS ще не вказує на edge.example.net')).toBeInTheDocument();
    });

    it('lets the read-only demo account run the connection check', async () => {
        vi.mocked(useAuth).mockReturnValue({
            user: { id: 1, name: 'Demo', email: 'demo@linkfleet.app', is_demo: true },
            loading: false,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
        });
        vi.mocked(domainsApi.getDomain).mockResolvedValue(verified);

        renderPage();

        expect(await screen.findByRole('button', { name: 'Перевірити підключення' })).toBeEnabled();
    });

    it('blocks the demo account from attaching a domain', async () => {
        vi.mocked(useAuth).mockReturnValue({
            user: { id: 1, name: 'Demo', email: 'demo@linkfleet.app', is_demo: true },
            loading: false,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
        });
        vi.mocked(domainsApi.getDomain).mockResolvedValue(null);

        renderPage();

        expect(await screen.findByRole('button', { name: 'Додати домен' })).toBeDisabled();
    });
});
