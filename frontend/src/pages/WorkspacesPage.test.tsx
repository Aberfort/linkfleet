import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import WorkspacesPage from './WorkspacesPage';
import { useAuth } from '../contexts/useAuth';
import * as workspacesApi from '../api/workspaces';
import type { Workspace } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../api/workspaces');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const owned: Workspace = {
    id: 1,
    name: 'Acme Agency',
    role: 'owner',
    members_count: 3,
    sites_count: 5,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};
const shared: Workspace = { ...owned, id: 2, name: 'Client B', role: 'viewer', members_count: 2, sites_count: 1 };

function renderPage() {
    return render(
        <MemoryRouter>
            <WorkspacesPage />
        </MemoryRouter>
    );
}

function signIn(isDemo = false) {
    vi.mocked(useAuth).mockReturnValue({
        user: { id: 1, name: 'User', email: 'u@example.com', is_demo: isDemo },
        loading: false,
        login: vi.fn(),
        register: vi.fn(),
        logout: vi.fn(),
    });
}

describe('WorkspacesPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        signIn();
    });

    it('lists workspaces with the users role and the counts', async () => {
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([owned, shared]);

        renderPage();

        expect(await screen.findByText('Acme Agency')).toBeInTheDocument();
        expect(screen.getByText('Власник')).toBeInTheDocument();
        expect(screen.getByText('Глядач')).toBeInTheDocument();
        expect(screen.getByText('5')).toBeInTheDocument();
    });

    it('lets only an owner rename or delete', async () => {
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([owned, shared]);

        renderPage();
        await screen.findByText('Acme Agency');

        const rename = screen.getAllByRole('button', { name: 'Перейменувати' });
        const remove = screen.getAllByRole('button', { name: 'Видалити' });
        expect(rename[0]).toBeEnabled();
        expect(remove[0]).toBeEnabled();
        expect(rename[1]).toBeDisabled();
        expect(remove[1]).toBeDisabled();
    });

    it('creates a workspace', async () => {
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([owned]);
        vi.mocked(workspacesApi.createWorkspace).mockResolvedValue({ ...owned, id: 9, name: 'New Team' });

        renderPage();
        await screen.findByText('Acme Agency');

        await userEvent.click(screen.getByRole('button', { name: 'Створити workspace' }));
        await userEvent.type(screen.getByLabelText('Назва'), 'New Team');
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        await waitFor(() => expect(workspacesApi.createWorkspace).toHaveBeenCalledWith('New Team'));
        expect(await screen.findByText('New Team')).toBeInTheDocument();
    });

    it('removes a deleted workspace from the list after confirming', async () => {
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([owned]);
        vi.mocked(workspacesApi.deleteWorkspace).mockResolvedValue({} as never);
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderPage();
        await screen.findByText('Acme Agency');
        await userEvent.click(screen.getByRole('button', { name: 'Видалити' }));

        await waitFor(() => expect(workspacesApi.deleteWorkspace).toHaveBeenCalledWith(1));
        await waitFor(() => expect(screen.queryByText('Acme Agency')).not.toBeInTheDocument());
    });

    it('keeps the list when the confirmation is declined', async () => {
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([owned]);
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        renderPage();
        await screen.findByText('Acme Agency');
        await userEvent.click(screen.getByRole('button', { name: 'Видалити' }));

        expect(workspacesApi.deleteWorkspace).not.toHaveBeenCalled();
        expect(screen.getByText('Acme Agency')).toBeInTheDocument();
    });

    it('is read-only for the demo account', async () => {
        signIn(true);
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([owned]);

        renderPage();
        await screen.findByText('Acme Agency');

        expect(screen.getByRole('button', { name: 'Створити workspace' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Видалити' })).toBeDisabled();
        expect(screen.getByText(/лише для читання/i)).toBeInTheDocument();
    });
});
