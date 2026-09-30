import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import SitesPage from './SitesPage';
import { useAuth } from '../contexts/useAuth';
import * as sitesApi from '../api/sites';
import * as workspacesApi from '../api/workspaces';
import type { Site, Workspace } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../api/sites');
vi.mock('../api/workspaces');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const site: Site = {
    id: 1,
    workspace_id: 1,
    workspace: { id: 1, name: 'Acme' },
    role: 'owner',
    name: 'My Site',
    domain: 'example.com',
    description: null,
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

    it('shows an empty state when there are no sites', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([]);

        renderPage();

        expect(await screen.findByText('Сайтів поки немає.')).toBeInTheDocument();
    });

    it('creates a site through the dialog', async () => {
        vi.mocked(sitesApi.listSites).mockResolvedValue([]);
        vi.mocked(sitesApi.createSite).mockResolvedValue({ ...site, name: 'New Site' });

        renderPage();
        await screen.findByText('Сайтів поки немає.');

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
        await screen.findByText('Сайтів поки немає.');
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
});
