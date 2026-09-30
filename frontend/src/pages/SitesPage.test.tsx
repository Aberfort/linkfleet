import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import SitesPage from './SitesPage';
import { useAuth } from '../contexts/useAuth';
import * as sitesApi from '../api/sites';
import * as workspacesApi from '../api/workspaces';
import * as linksApi from '../api/links';
import type { Site, Workspace } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../api/sites');
vi.mock('../api/workspaces');
vi.mock('../api/links');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const site: Site = {
    id: 1,
    workspace_id: 1,
    workspace: { id: 1, name: 'Acme' },
    role: 'owner',
    name: 'My Site',
    domain: 'example.com',
    description: null,
    conversion_tracking: false,
    links_count: 3,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

const workspace: Workspace = {
    id: 1,
    name: 'Acme',
    role: 'owner',
    members_count: 1,
    sites_count: 1,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

function renderPage() {
    return render(
        <MemoryRouter>
            <SitesPage />
        </MemoryRouter>
    );
}

describe('SitesPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([workspace]);
        vi.mocked(useAuth).mockReturnValue({
            user: { id: 1, name: 'User', email: 'u@example.com', is_demo: false },
            loading: false,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
        });
    });

    it('lists the users sites', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([site]);

        renderPage();

        expect(await screen.findByText('My Site')).toBeInTheDocument();
        expect(screen.getByText('example.com')).toBeInTheDocument();
        expect(screen.getByText('Acme')).toBeInTheDocument();
    });

    it('walks someone with no sites to their first link instead of an empty table', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([]);

        renderPage();

        expect(await screen.findByText('Створіть перше посилання')).toBeInTheDocument();
        expect(screen.getByLabelText('Куди веде посилання')).toBeInTheDocument();
        expect(screen.queryByText('Сайтів поки немає.')).not.toBeInTheDocument();
    });

    it('does the same for a site that has no links yet', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([{ ...site, links_count: 0 }]);

        renderPage();

        expect(await screen.findByText('Створіть перше посилання')).toBeInTheDocument();
        expect(screen.getByText('My Site')).toBeInTheDocument(); // the table is still there
    });

    it('leaves people who already have links alone', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([site]); // 3 links

        renderPage();
        await screen.findByText('My Site');

        expect(screen.queryByText('Створіть перше посилання')).not.toBeInTheDocument();
    });

    it('keeps the card on screen once the first link exists, until the person is done with it', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValueOnce([]).mockResolvedValue([{ ...site, links_count: 1 }]);
        vi.mocked(sitesApi.createSite).mockResolvedValue({ ...site, links_count: 0 });
        vi.mocked(linksApi.createLink).mockResolvedValue({
            id: 1, site_id: 1, short_code: 'abc', target_url: 'https://example.com/x', is_active: true, clicks_count: 0,
            expires_at: null, has_password: false, short_url: 'https://go.example.com/abc',
            created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z',
        });

        renderPage();
        await userEvent.type(await screen.findByLabelText('Куди веде посилання'), 'https://example.com/x');
        await userEvent.click(screen.getByRole('button', { name: 'Скоротити' }));

        expect(await screen.findByText('https://go.example.com/abc')).toBeInTheDocument();
        expect(sitesApi.listSites).toHaveBeenCalledTimes(1); // nothing reloaded under their feet

        await userEvent.click(screen.getByRole('button', { name: 'Готово' }));

        await waitFor(() => expect(screen.queryByText('https://go.example.com/abc')).not.toBeInTheDocument());
        expect(await screen.findByText('My Site')).toBeInTheDocument(); // and the table is now the real one
        expect(sitesApi.listSites).toHaveBeenCalledTimes(2);
    });

    it('stays closed once dismissed, even if reloading the list fails', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValueOnce([]).mockRejectedValue(new Error('offline'));
        vi.mocked(sitesApi.createSite).mockResolvedValue({ ...site, links_count: 0 });
        vi.mocked(linksApi.createLink).mockResolvedValue({
            id: 1, site_id: 1, short_code: 'abc', target_url: 'https://example.com/x', is_active: true, clicks_count: 0,
            expires_at: null, has_password: false, short_url: 'https://go.example.com/abc',
            created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z',
        });

        renderPage();
        await userEvent.type(await screen.findByLabelText('Куди веде посилання'), 'https://example.com/x');
        await userEvent.click(screen.getByRole('button', { name: 'Скоротити' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Готово' }));

        await waitFor(() => expect(sitesApi.listSites).toHaveBeenCalledTimes(2));
        await screen.findByText('Сайтів поки немає.');
        expect(screen.queryByText('Створіть перше посилання')).not.toBeInTheDocument();
    });

    it('does not offer it to the read-only demo account', async () => {
        vi.mocked(useAuth).mockReturnValue({
            user: { id: 1, name: 'Demo', email: 'demo@linkfleet.app', is_demo: true },
            loading: false,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
        });
        vi.mocked(sitesApi.listSites).mockResolvedValue([]);

        renderPage();
        await screen.findByText('Сайтів поки немає.');

        expect(screen.queryByText('Створіть перше посилання')).not.toBeInTheDocument();
    });

    it('does not offer it to someone who can only view', async () => {
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([{ ...workspace, role: 'viewer' }]);
        vi.mocked(sitesApi.listSites).mockResolvedValue([]);

        renderPage();
        await screen.findByText('Сайтів поки немає.');

        expect(screen.queryByText('Створіть перше посилання')).not.toBeInTheDocument();
    });

    it('creates a site through the dialog', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([]);
        vi.mocked(sitesApi.createSite).mockResolvedValue({ ...site, name: 'New Site' });

        renderPage();
        await screen.findByText('Створіть перше посилання');

        await userEvent.click(screen.getByRole('button', { name: 'Додати сайт' }));
        await userEvent.type(screen.getByLabelText('Назва'), 'New Site');
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        await waitFor(() =>
            expect(sitesApi.createSite).toHaveBeenCalledWith(
                expect.objectContaining({ name: 'New Site', workspace_id: 1 })
            )
        );
    });

    it('disables write actions for the demo account', async () => {
        vi.mocked(useAuth).mockReturnValue({
            user: { id: 1, name: 'Demo', email: 'demo@linkfleet.app', is_demo: true },
            loading: false,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
        });
        vi.mocked(sitesApi.listSites).mockResolvedValue([site]);

        renderPage();
        await screen.findByText('My Site');

        expect(screen.getByRole('button', { name: 'Додати сайт' })).toBeDisabled();
        expect(screen.getByText(/лише для читання/i)).toBeInTheDocument();
    });

    it('asks which workspace when the user can write to more than one', async () => {
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([
            workspace,
            { ...workspace, id: 2, name: 'Client B', role: 'editor' },
            { ...workspace, id: 3, name: 'Read Only Co', role: 'viewer' },
        ]);
        vi.mocked(sitesApi.listSites).mockResolvedValue([]);
        vi.mocked(sitesApi.createSite).mockResolvedValue({ ...site, name: 'New Site' });

        renderPage();
        await screen.findByText('Створіть перше посилання');
        await userEvent.click(screen.getByRole('button', { name: 'Додати сайт' }));

        await userEvent.click(screen.getByLabelText('Workspace'));
        // A viewer-only workspace is not offered: nothing could be created there.
        expect(screen.queryByRole('option', { name: 'Read Only Co' })).not.toBeInTheDocument();
        await userEvent.click(screen.getByRole('option', { name: 'Client B' }));

        await userEvent.type(screen.getByLabelText('Назва'), 'New Site');
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        await waitFor(() =>
            expect(sitesApi.createSite).toHaveBeenCalledWith(expect.objectContaining({ workspace_id: 2 }))
        );
    });

    it('cannot add a site when the user may not edit any workspace', async () => {
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([{ ...workspace, role: 'viewer' }]);
        vi.mocked(sitesApi.listSites).mockResolvedValue([]);

        renderPage();
        await screen.findByText('Сайтів поки немає.');

        expect(screen.getByRole('button', { name: 'Додати сайт' })).toBeDisabled();
    });

    it('lets an editor change a site but not delete it', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([{ ...site, role: 'editor' }]);

        renderPage();
        await screen.findByText('My Site');

        // The row's icon buttons carry no accessible name, so pick them by their MUI icon.
        const edit = screen.getByTestId('EditIcon').closest('button');
        const remove = screen.getByTestId('DeleteIcon').closest('button');
        expect(edit).toBeEnabled();
        expect(remove).toBeDisabled();
    });

    it('lets a viewer only read', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([{ ...site, role: 'viewer' }]);

        renderPage();
        await screen.findByText('My Site');

        expect(screen.getByTestId('EditIcon').closest('button')).toBeDisabled();
        expect(screen.getByTestId('DeleteIcon').closest('button')).toBeDisabled();
    });

    it('turns conversion tracking on when creating a site', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([]);
        vi.mocked(sitesApi.createSite).mockResolvedValue({ ...site, name: 'Tracked' });

        renderPage();
        await screen.findByText('Створіть перше посилання');
        await userEvent.click(screen.getByRole('button', { name: 'Додати сайт' }));
        await userEvent.type(screen.getByLabelText('Назва'), 'Tracked');
        await userEvent.click(screen.getByRole('checkbox', { name: /Відстежувати конверсії/ }));
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        await waitFor(() =>
            expect(sitesApi.createSite).toHaveBeenCalledWith(expect.objectContaining({ name: 'Tracked', conversion_tracking: true }))
        );
    });

    it('starts with tracking off and shows the current setting when editing', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([{ ...site, conversion_tracking: true }]);
        vi.mocked(sitesApi.updateSite).mockResolvedValue({ ...site, conversion_tracking: false });

        renderPage();
        await screen.findByText('My Site');
        await userEvent.click(screen.getByTestId('EditIcon').closest('button')!);

        const toggle = screen.getByRole('checkbox', { name: /Відстежувати конверсії/ });
        expect(toggle).toBeChecked();
        await userEvent.click(toggle);
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        await waitFor(() =>
            expect(sitesApi.updateSite).toHaveBeenCalledWith(1, expect.objectContaining({ conversion_tracking: false }))
        );
    });

    it('links each site to its conversions page', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([site]);

        renderPage();
        await screen.findByText('My Site');

        expect(screen.getByRole('link', { name: 'Конверсії' })).toHaveAttribute('href', '/sites/1/conversions');
    });
});
