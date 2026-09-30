import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import WorkspaceMembersPage from './WorkspaceMembersPage';
import { useAuth } from '../contexts/useAuth';
import * as workspacesApi from '../api/workspaces';
import type { Member, Workspace } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../api/workspaces');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const workspace: Workspace = {
    id: 1,
    name: 'Acme Agency',
    role: 'owner',
    members_count: 2,
    sites_count: 0,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};
const me: Member = { user_id: 1, name: 'Olga', email: 'olga@example.com', role: 'owner', joined_at: '2026-01-01T00:00:00Z' };
const colleague: Member = { user_id: 2, name: 'Petro', email: 'petro@example.com', role: 'viewer', joined_at: '2026-01-02T00:00:00Z' };

function renderPage() {
    return render(
        <MemoryRouter initialEntries={['/workspaces/1/members']}>
            <Routes>
                <Route path="/workspaces/:workspaceId/members" element={<WorkspaceMembersPage />} />
                <Route path="/workspaces" element={<div>workspaces list</div>} />
            </Routes>
        </MemoryRouter>
    );
}

function signIn(isDemo = false) {
    vi.mocked(useAuth).mockReturnValue({
        user: { id: 1, name: 'Olga', email: 'olga@example.com', is_demo: isDemo },
        loading: false,
        login: vi.fn(),
        register: vi.fn(),
        logout: vi.fn(),
    });
}

describe('WorkspaceMembersPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        signIn();
        vi.mocked(workspacesApi.getWorkspace).mockResolvedValue(workspace);
        vi.mocked(workspacesApi.listMembers).mockResolvedValue([me, colleague]);
    });

    it('lists the members and marks the signed-in user', async () => {
        renderPage();

        expect(await screen.findByText('petro@example.com')).toBeInTheDocument();
        expect(screen.getByText('(ви)')).toBeInTheDocument();
    });

    it('adds a member by email with the chosen role', async () => {
        vi.mocked(workspacesApi.addMember).mockResolvedValue({
            user_id: 3, name: 'Ihor', email: 'ihor@example.com', role: 'editor', joined_at: '2026-01-03T00:00:00Z',
        });

        renderPage();
        await screen.findByText('petro@example.com');

        await userEvent.type(screen.getByLabelText('Email'), 'ihor@example.com');
        await userEvent.click(screen.getByRole('button', { name: 'Додати' }));

        await waitFor(() =>
            expect(workspacesApi.addMember).toHaveBeenCalledWith(1, 'ihor@example.com', 'editor')
        );
        expect(await screen.findByText('ihor@example.com')).toBeInTheDocument();
    });

    it('rejects a malformed email before calling the API', async () => {
        renderPage();
        await screen.findByText('petro@example.com');

        await userEvent.type(screen.getByLabelText('Email'), 'not-an-email');
        await userEvent.click(screen.getByRole('button', { name: 'Додати' }));

        expect(await screen.findByText('Некоректний email')).toBeInTheDocument();
        expect(workspacesApi.addMember).not.toHaveBeenCalled();
    });

    it('removes another member after confirming', async () => {
        vi.mocked(workspacesApi.removeMember).mockResolvedValue({} as never);
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderPage();
        await screen.findByText('petro@example.com');
        await userEvent.click(screen.getByRole('button', { name: 'Прибрати' }));

        await waitFor(() => expect(workspacesApi.removeMember).toHaveBeenCalledWith(1, 2));
        await waitFor(() => expect(screen.queryByText('petro@example.com')).not.toBeInTheDocument());
    });

    it('offers a member only the way out, not the management controls', async () => {
        signIn();
        vi.mocked(workspacesApi.getWorkspace).mockResolvedValue({ ...workspace, role: 'viewer' });
        vi.mocked(workspacesApi.listMembers).mockResolvedValue([{ ...me, role: 'viewer' }, { ...colleague, role: 'owner' }]);

        renderPage();
        await screen.findByText('petro@example.com');

        expect(screen.queryByLabelText('Email')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Прибрати' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Вийти' })).toBeInTheDocument();
    });

    it('leaves the workspace and goes back to the list', async () => {
        vi.mocked(workspacesApi.getWorkspace).mockResolvedValue({ ...workspace, role: 'viewer' });
        vi.mocked(workspacesApi.listMembers).mockResolvedValue([{ ...me, role: 'viewer' }, { ...colleague, role: 'owner' }]);
        vi.mocked(workspacesApi.removeMember).mockResolvedValue({} as never);
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderPage();
        await screen.findByText('petro@example.com');
        await userEvent.click(screen.getByRole('button', { name: 'Вийти' }));

        await waitFor(() => expect(workspacesApi.removeMember).toHaveBeenCalledWith(1, 1));
        expect(await screen.findByText('workspaces list')).toBeInTheDocument();
    });

    it('shows nothing to manage for the demo account, and no way to leave', async () => {
        signIn(true);

        renderPage();
        await screen.findByText('petro@example.com');

        expect(screen.queryByLabelText('Email')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Вийти' })).not.toBeInTheDocument();
        expect(screen.getByText(/лише для читання/i)).toBeInTheDocument();
    });

    it('does not offer the only owner a way out, or a way to demote themselves', async () => {
        renderPage();
        await screen.findByText('petro@example.com');

        expect(screen.getByText('Єдиний власник')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Вийти' })).not.toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: 'Роль: Olga' })).toHaveAttribute('aria-disabled', 'true');
        // Other members are still manageable.
        expect(screen.getByRole('button', { name: 'Прибрати' })).toBeInTheDocument();
    });

    it('lets an owner step down once there is a second owner', async () => {
        vi.mocked(workspacesApi.listMembers).mockResolvedValue([me, { ...colleague, role: 'owner' }]);

        renderPage();
        await screen.findByText('petro@example.com');

        expect(screen.queryByText('Єдиний власник')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Вийти' })).toBeInTheDocument();
    });
});
