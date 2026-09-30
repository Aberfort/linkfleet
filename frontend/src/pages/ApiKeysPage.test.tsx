import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ApiKeysPage from './ApiKeysPage';
import { useAuth } from '../contexts/useAuth';
import * as keysApi from '../api/apiKeys';
import * as workspacesApi from '../api/workspaces';
import type { ApiKey, Workspace } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../api/apiKeys');
vi.mock('../api/workspaces');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const workspace: Workspace = {
    id: 1,
    name: 'Acme Agency',
    role: 'owner',
    members_count: 1,
    sites_count: 1,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

const zapier: ApiKey = {
    id: 5,
    name: 'Zapier',
    access: 'write',
    workspace_id: 1,
    last_used_at: '2026-02-01T10:00:00Z',
    created_at: '2026-01-15T09:00:00Z',
};
const reporting: ApiKey = {
    id: 6,
    name: 'Reporting',
    access: 'read',
    workspace_id: null,
    last_used_at: null,
    created_at: '2026-01-20T09:00:00Z',
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

describe('ApiKeysPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        signIn();
        vi.mocked(workspacesApi.listWorkspaces).mockResolvedValue([workspace]);
    });

    it('lists keys with their access, scope and last use', async () => {
        vi.mocked(keysApi.listApiKeys).mockResolvedValue([zapier, reporting]);

        render(<ApiKeysPage />);

        expect(await screen.findByText('Zapier')).toBeInTheDocument();
        expect(screen.getByText('Читання й запис')).toBeInTheDocument();
        expect(screen.getByText('Acme Agency')).toBeInTheDocument();
        expect(screen.getByText('Усі workspaces')).toBeInTheDocument();
        expect(screen.getByText('Ніколи')).toBeInTheDocument();
    });

    it('says so when a key points at a workspace the user can no longer see', async () => {
        vi.mocked(keysApi.listApiKeys).mockResolvedValue([{ ...zapier, workspace_id: 99 }]);

        render(<ApiKeysPage />);

        expect(await screen.findByText('Workspace #99 (недоступний)')).toBeInTheDocument();
    });

    it('creates a key, shows the secret once, and keeps it out of the list', async () => {
        vi.mocked(keysApi.listApiKeys).mockResolvedValue([]);
        vi.mocked(keysApi.createApiKey).mockResolvedValue({ ...zapier, token: '7|lf_secretvalue123' });

        render(<ApiKeysPage />);
        await screen.findByText('Ключів поки немає.');

        await userEvent.click(screen.getByRole('button', { name: 'Створити ключ' }));
        await userEvent.type(screen.getByLabelText('Назва'), 'Zapier');
        await userEvent.click(screen.getByRole('button', { name: 'Створити' }));

        await waitFor(() =>
            expect(keysApi.createApiKey).toHaveBeenCalledWith({ name: 'Zapier', access: 'read', workspace_id: null })
        );
        expect(await screen.findByTestId('created-key')).toHaveTextContent('7|lf_secretvalue123');
        expect(screen.getByText('Скопіюйте ключ зараз')).toBeInTheDocument();

        // Once dismissed the secret is gone from the page, but the key stays listed.
        await userEvent.click(screen.getByRole('button', { name: 'Я скопіював ключ' }));
        await waitFor(() => expect(screen.queryByTestId('created-key')).not.toBeInTheDocument());
        expect(screen.queryByText(/lf_secretvalue123/)).not.toBeInTheDocument();
        expect(screen.getByText('Zapier')).toBeInTheDocument();
    });

    it('lets a key be limited to one workspace with write access', async () => {
        vi.mocked(keysApi.listApiKeys).mockResolvedValue([]);
        vi.mocked(keysApi.createApiKey).mockResolvedValue({ ...zapier, token: '7|lf_x' });

        render(<ApiKeysPage />);
        await screen.findByText('Ключів поки немає.');
        await userEvent.click(screen.getByRole('button', { name: 'Створити ключ' }));
        await userEvent.type(screen.getByLabelText('Назва'), 'Zapier');

        await userEvent.click(screen.getByLabelText('Доступ'));
        await userEvent.click(screen.getByRole('option', { name: 'Читання й запис' }));
        await userEvent.click(screen.getByLabelText('Охоплення'));
        await userEvent.click(screen.getByRole('option', { name: 'Acme Agency' }));
        await userEvent.click(screen.getByRole('button', { name: 'Створити' }));

        await waitFor(() =>
            expect(keysApi.createApiKey).toHaveBeenCalledWith({ name: 'Zapier', access: 'write', workspace_id: 1 })
        );
    });

    it('requires a name', async () => {
        vi.mocked(keysApi.listApiKeys).mockResolvedValue([]);

        render(<ApiKeysPage />);
        await screen.findByText('Ключів поки немає.');
        await userEvent.click(screen.getByRole('button', { name: 'Створити ключ' }));
        await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Створити' }));

        expect(await screen.findByText("Назва є обов'язковою")).toBeInTheDocument();
        expect(keysApi.createApiKey).not.toHaveBeenCalled();
    });

    it('revokes a key after confirming', async () => {
        vi.mocked(keysApi.listApiKeys).mockResolvedValue([zapier, reporting]);
        vi.mocked(keysApi.revokeApiKey).mockResolvedValue({} as never);
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        render(<ApiKeysPage />);
        await screen.findByText('Zapier');
        await userEvent.click(screen.getByRole('button', { name: 'Відкликати Zapier' }));

        await waitFor(() => expect(keysApi.revokeApiKey).toHaveBeenCalledWith(5));
        await waitFor(() => expect(screen.queryByText('Zapier')).not.toBeInTheDocument());
        expect(screen.getByText('Reporting')).toBeInTheDocument();
    });

    it('keeps the key when the revoke is not confirmed', async () => {
        vi.mocked(keysApi.listApiKeys).mockResolvedValue([zapier]);
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        render(<ApiKeysPage />);
        await screen.findByText('Zapier');
        await userEvent.click(screen.getByRole('button', { name: 'Відкликати Zapier' }));

        expect(keysApi.revokeApiKey).not.toHaveBeenCalled();
        expect(screen.getByText('Zapier')).toBeInTheDocument();
    });

    it('does not let the demo account create keys', async () => {
        signIn(true);
        vi.mocked(keysApi.listApiKeys).mockResolvedValue([]);

        render(<ApiKeysPage />);
        await screen.findByText('Ключів поки немає.');

        expect(screen.getByRole('button', { name: 'Створити ключ' })).toBeDisabled();
        expect(screen.getByText(/лише для читання/i)).toBeInTheDocument();
    });
});
