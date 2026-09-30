import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import WebhooksPage from './WebhooksPage';
import { useAuth } from '../contexts/useAuth';
import * as workspacesApi from '../api/workspaces';
import * as hooksApi from '../api/webhooks';
import type { Webhook, WebhookDelivery, Workspace } from '../types';

vi.mock('../contexts/useAuth');
vi.mock('../api/workspaces');
vi.mock('../api/webhooks');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const workspace: Workspace = {
    id: 1,
    name: 'Acme Agency',
    role: 'owner',
    members_count: 2,
    sites_count: 1,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

const hook: Webhook = {
    id: 7,
    workspace_id: 1,
    url: 'https://hooks.example.com/in',
    events: ['link.clicked', 'link.created'],
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    latest_delivery: { success: true, status_code: 200, event: 'link.clicked', created_at: '2026-02-01T10:00:00Z' },
};

const delivery: WebhookDelivery = {
    id: 90,
    webhook_id: 7,
    event: 'link.clicked',
    payload: { type: 'link.clicked', data: { link: { short_code: 'promo' } } },
    status_code: 503,
    success: false,
    error: 'Отримувач відповів 503.',
    response_excerpt: 'try later',
    attempt: 2,
    duration_ms: 431,
    created_at: '2026-02-01T10:00:00Z',
};

function renderPage() {
    return render(
        <MemoryRouter initialEntries={['/workspaces/1/webhooks']}>
            <Routes>
                <Route path="/workspaces/:workspaceId/webhooks" element={<WebhooksPage />} />
            </Routes>
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

describe('WebhooksPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        signIn();
        vi.mocked(workspacesApi.getWorkspace).mockResolvedValue(workspace);
        vi.mocked(hooksApi.listWebhooks).mockResolvedValue([hook]);
    });

    it('lists webhooks with their events and how the last delivery went', async () => {
        renderPage();

        expect(await screen.findByText('https://hooks.example.com/in')).toBeInTheDocument();
        expect(screen.getByText('link.clicked')).toBeInTheDocument();
        expect(screen.getByText('link.created')).toBeInTheDocument();
        expect(screen.getByText('200')).toBeInTheDocument();
    });

    it('says so for a webhook that has not delivered anything yet', async () => {
        vi.mocked(hooksApi.listWebhooks).mockResolvedValue([{ ...hook, latest_delivery: null }]);

        renderPage();

        expect(await screen.findByText('Ще не було')).toBeInTheDocument();
    });

    it('creates a webhook, then shows its secret once', async () => {
        vi.mocked(hooksApi.listWebhooks).mockResolvedValue([]);
        vi.mocked(hooksApi.createWebhook).mockResolvedValue({ ...hook, secret: 'whsec_topsecretvalue' });

        renderPage();
        await screen.findByText('Вебхуків поки немає.');
        await userEvent.click(screen.getByRole('button', { name: 'Додати вебхук' }));
        await userEvent.type(screen.getByLabelText('Адреса'), 'https://hooks.example.com/in');
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        await waitFor(() =>
            expect(hooksApi.createWebhook).toHaveBeenCalledWith(1, {
                url: 'https://hooks.example.com/in',
                events: ['link.created', 'link.clicked'],
            })
        );
        expect(await screen.findByTestId('webhook-secret')).toHaveTextContent('whsec_topsecretvalue');

        await userEvent.click(screen.getByRole('button', { name: 'Я зберіг секрет' }));
        await waitFor(() => expect(screen.queryByTestId('webhook-secret')).not.toBeInTheDocument());
        // The row stays, the secret does not.
        expect(screen.queryByText('whsec_topsecretvalue')).not.toBeInTheDocument();
        expect(screen.getByText('https://hooks.example.com/in')).toBeInTheDocument();
    });

    it('lets the chosen events be changed', async () => {
        vi.mocked(hooksApi.listWebhooks).mockResolvedValue([]);
        vi.mocked(hooksApi.createWebhook).mockResolvedValue({ ...hook, secret: 'whsec_x' });

        renderPage();
        await screen.findByText('Вебхуків поки немає.');
        await userEvent.click(screen.getByRole('button', { name: 'Додати вебхук' }));
        await userEvent.type(screen.getByLabelText('Адреса'), 'https://hooks.example.com/in');
        await userEvent.click(screen.getByLabelText(/Посилання створено/));
        await userEvent.click(screen.getByLabelText(/Клік по посиланню/));
        await userEvent.click(screen.getByLabelText(/Посилання видалено/));
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        await waitFor(() =>
            expect(hooksApi.createWebhook).toHaveBeenCalledWith(1, {
                url: 'https://hooks.example.com/in',
                events: ['link.deleted'],
            })
        );
    });

    it('will not save without an address or without any event', async () => {
        vi.mocked(hooksApi.listWebhooks).mockResolvedValue([]);

        renderPage();
        await screen.findByText('Вебхуків поки немає.');
        await userEvent.click(screen.getByRole('button', { name: 'Додати вебхук' }));
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));
        expect(await screen.findByText("Адреса є обов'язковою")).toBeInTheDocument();

        await userEvent.type(screen.getByLabelText('Адреса'), 'https://hooks.example.com/in');
        await userEvent.click(screen.getByLabelText(/Посилання створено/));
        await userEvent.click(screen.getByLabelText(/Клік по посиланню/));
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        expect(await screen.findByText('Оберіть хоча б одну подію')).toBeInTheDocument();
        expect(hooksApi.createWebhook).not.toHaveBeenCalled();
    });

    it('shows the servers reason when it refuses an address', async () => {
        vi.mocked(hooksApi.listWebhooks).mockResolvedValue([]);
        vi.mocked(hooksApi.createWebhook).mockRejectedValue(
            Object.assign(new (await import('axios')).AxiosError('x'), {
                response: { status: 422, data: { message: 'x', errors: { url: ['Ця адреса веде у внутрішню мережу, тому її заборонено.'] } } },
            })
        );

        renderPage();
        await screen.findByText('Вебхуків поки немає.');
        await userEvent.click(screen.getByRole('button', { name: 'Додати вебхук' }));
        await userEvent.type(screen.getByLabelText('Адреса'), 'https://10.0.0.1/x');
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        expect(await screen.findByText('Ця адреса веде у внутрішню мережу, тому її заборонено.')).toBeInTheDocument();
    });

    it('switches a webhook off', async () => {
        vi.mocked(hooksApi.updateWebhook).mockResolvedValue({ ...hook, is_active: false });

        renderPage();
        await screen.findByText('https://hooks.example.com/in');
        await userEvent.click(screen.getByRole('checkbox', { name: /Активний/ }));

        await waitFor(() => expect(hooksApi.updateWebhook).toHaveBeenCalledWith(7, { is_active: false }));
        await waitFor(() => expect(screen.getByRole('checkbox', { name: /Активний/ })).not.toBeChecked());
    });

    it('sends a test and reports the outcome', async () => {
        vi.mocked(hooksApi.testWebhook).mockResolvedValue({ ...delivery, event: 'ping', success: true, status_code: 200, error: null });

        renderPage();
        await screen.findByText('https://hooks.example.com/in');
        await userEvent.click(screen.getByRole('button', { name: 'Тест' }));

        await waitFor(() => expect(hooksApi.testWebhook).toHaveBeenCalledWith(7));
    });

    it('rotates the secret after confirming and shows the new one once', async () => {
        vi.mocked(hooksApi.rotateWebhookSecret).mockResolvedValue({ ...hook, secret: 'whsec_brandnew' });
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderPage();
        await screen.findByText('https://hooks.example.com/in');
        await userEvent.click(screen.getByRole('button', { name: 'Новий секрет' }));

        expect(await screen.findByTestId('webhook-secret')).toHaveTextContent('whsec_brandnew');
    });

    it('does not rotate when the confirmation is declined', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        renderPage();
        await screen.findByText('https://hooks.example.com/in');
        await userEvent.click(screen.getByRole('button', { name: 'Новий секрет' }));

        expect(hooksApi.rotateWebhookSecret).not.toHaveBeenCalled();
    });

    it('deletes a webhook after confirming', async () => {
        vi.mocked(hooksApi.deleteWebhook).mockResolvedValue({} as never);
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderPage();
        await screen.findByText('https://hooks.example.com/in');
        await userEvent.click(screen.getByRole('button', { name: /Видалити/ }));

        await waitFor(() => expect(hooksApi.deleteWebhook).toHaveBeenCalledWith(7));
        await waitFor(() => expect(screen.queryByText('https://hooks.example.com/in')).not.toBeInTheDocument());
    });

    it('opens the delivery log and expands a row to show what was sent and what came back', async () => {
        vi.mocked(hooksApi.listDeliveries).mockResolvedValue([delivery]);

        renderPage();
        await screen.findByText('https://hooks.example.com/in');
        await userEvent.click(screen.getByRole('button', { name: 'Журнал' }));

        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText('Клік по посиланню')).toBeInTheDocument();
        expect(within(dialog).getByText('503')).toBeInTheDocument();

        await userEvent.click(within(dialog).getByText('Клік по посиланню'));
        expect(await within(dialog).findByText('Отримувач відповів 503.')).toBeInTheDocument();
        expect(within(dialog).getByText('try later')).toBeInTheDocument();
        expect(within(dialog).getByText(/"short_code": "promo"/)).toBeInTheDocument();
    });

    it('says so when a receiver was never reachable at all', async () => {
        vi.mocked(hooksApi.listDeliveries).mockResolvedValue([{ ...delivery, status_code: null, response_excerpt: null }]);

        renderPage();
        await screen.findByText('https://hooks.example.com/in');
        await userEvent.click(screen.getByRole('button', { name: 'Журнал' }));

        expect(await screen.findByText('без відповіді')).toBeInTheDocument();
    });

    it('does not even ask an editor for the list, and explains why', async () => {
        vi.mocked(workspacesApi.getWorkspace).mockResolvedValue({ ...workspace, role: 'editor' });

        renderPage();

        expect(await screen.findByText(/Вебхуками керують лише власники/)).toBeInTheDocument();
        expect(hooksApi.listWebhooks).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Додати вебхук' })).toBeDisabled();
    });

    it('lets the demo account look but not change anything', async () => {
        signIn(true);

        renderPage();
        await screen.findByText('https://hooks.example.com/in');

        expect(screen.getByRole('button', { name: 'Додати вебхук' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Тест' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Новий секрет' })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Видалити/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Журнал' })).toBeEnabled();
        expect(screen.getByText(/лише для читання/i)).toBeInTheDocument();
    });

    it('can subscribe to new conversions', async () => {
        vi.mocked(hooksApi.listWebhooks).mockResolvedValue([]);
        vi.mocked(hooksApi.createWebhook).mockResolvedValue({ ...hook, secret: 'whsec_x' });

        renderPage();
        await screen.findByText('Вебхуків поки немає.');
        await userEvent.click(screen.getByRole('button', { name: 'Додати вебхук' }));
        await userEvent.type(screen.getByLabelText('Адреса'), 'https://hooks.example.com/in');
        await userEvent.click(screen.getByLabelText(/Нова конверсія/));
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        await waitFor(() =>
            expect(hooksApi.createWebhook).toHaveBeenCalledWith(1, {
                url: 'https://hooks.example.com/in',
                events: ['link.created', 'link.clicked', 'conversion.created'],
            })
        );
    });
});
