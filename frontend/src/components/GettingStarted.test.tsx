import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { AxiosError, AxiosHeaders } from 'axios';
import GettingStarted, { FIRST_SITE_NAME } from './GettingStarted';
import * as sitesApi from '../api/sites';
import * as linksApi from '../api/links';
import type { Link, Site, Workspace } from '../types';

vi.mock('../api/sites');
vi.mock('../api/links');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const workspace: Workspace = {
    id: 3,
    name: 'Мій workspace',
    role: 'owner',
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

const site: Site = {
    id: 9,
    workspace_id: 3,
    role: 'owner',
    name: 'Existing',
    domain: null,
    description: null,
    conversion_tracking: false,
    links_count: 0,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

const link: Link = {
    id: 1,
    site_id: 9,
    short_code: 'abc123',
    target_url: 'https://example.com/page',
    is_active: true,
    clicks_count: 0,
    expires_at: null,
    has_password: false,
    short_url: 'https://go.example.com/abc123',
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
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

function renderCard(props: Partial<React.ComponentProps<typeof GettingStarted>> = {}) {
    const onFinished = vi.fn();
    render(
        <MemoryRouter>
            <GettingStarted sites={[]} workspaces={[workspace]} onFinished={onFinished} {...props} />
        </MemoryRouter>
    );

    return { onFinished };
}

async function shorten(url = 'https://example.com/page') {
    await userEvent.type(screen.getByLabelText('Куди веде посилання'), url);
    await userEvent.click(screen.getByRole('button', { name: 'Скоротити' }));
}

describe('GettingStarted', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(sitesApi.createSite).mockResolvedValue({ ...site, name: FIRST_SITE_NAME });
        vi.mocked(linksApi.createLink).mockResolvedValue(link);
    });

    it('asks for one thing only', () => {
        renderCard();

        expect(screen.getByLabelText('Куди веде посилання')).toBeInTheDocument();
        expect(screen.getAllByRole('textbox')).toHaveLength(1);
    });

    it('makes the first site and the link behind the scenes, then shows the short URL', async () => {
        renderCard();

        await shorten();

        expect(await screen.findByText('https://go.example.com/abc123')).toBeInTheDocument();
        expect(sitesApi.createSite).toHaveBeenCalledWith({ workspace_id: 3, name: FIRST_SITE_NAME });
        expect(linksApi.createLink).toHaveBeenCalledWith(9, { target_url: 'https://example.com/page' });
    });

    it('offers the next things to do: open it, see the analytics, go to the links', async () => {
        renderCard();
        await shorten();

        await screen.findByText('Готово — ось ваше перше посилання');
        expect(screen.getByRole('link', { name: 'Відкрити посилання' })).toHaveAttribute('href', 'https://go.example.com/abc123');
        expect(screen.getByRole('link', { name: 'Відкрити посилання' })).toHaveAttribute('target', '_blank');
        expect(screen.getByRole('link', { name: 'Аналітика' })).toHaveAttribute('href', '/sites/9/analytics');
        expect(screen.getByRole('link', { name: 'Усі посилання' })).toHaveAttribute('href', '/sites/9/links');
    });

    it('uses a site the person already has instead of making another', async () => {
        renderCard({ sites: [site] });

        await shorten();

        await screen.findByText('Готово — ось ваше перше посилання');
        expect(sitesApi.createSite).not.toHaveBeenCalled();
        expect(linksApi.createLink).toHaveBeenCalledWith(9, expect.anything());
    });

    it('does not put a first link in a site the person may only view', async () => {
        vi.mocked(sitesApi.createSite).mockResolvedValue({ ...site, id: 77, name: FIRST_SITE_NAME });
        renderCard({ sites: [{ ...site, role: 'viewer' }] }); // site 9 is read-only for them

        await shorten();

        await waitFor(() => expect(linksApi.createLink).toHaveBeenCalledWith(77, expect.anything()));
        expect(linksApi.createLink).not.toHaveBeenCalledWith(9, expect.anything());
    });

    it('prefers a workspace the person owns', async () => {
        renderCard({
            workspaces: [
                { ...workspace, id: 1, name: 'A team', role: 'editor' },
                { ...workspace, id: 2, name: 'Mine', role: 'owner' },
            ],
        });

        await shorten();

        await waitFor(() =>
            expect(sitesApi.createSite).toHaveBeenCalledWith({ workspace_id: 2, name: FIRST_SITE_NAME })
        );
    });

    it('shows nothing when there is nowhere the person could put a link', () => {
        renderCard({ workspaces: [{ ...workspace, role: 'viewer' }] });

        expect(screen.queryByText('Створіть перше посилання')).not.toBeInTheDocument();
    });

    it('does not make a second site when the link is refused and the person tries again', async () => {
        vi.mocked(linksApi.createLink).mockRejectedValueOnce(failure(500, { message: 'oops' })).mockResolvedValueOnce(link);
        renderCard();

        await shorten();
        await waitFor(() => expect(linksApi.createLink).toHaveBeenCalledTimes(1));
        await userEvent.click(screen.getByRole('button', { name: 'Скоротити' }));

        await screen.findByText('Готово — ось ваше перше посилання');
        expect(sitesApi.createSite).toHaveBeenCalledTimes(1);
        expect(linksApi.createLink).toHaveBeenCalledTimes(2);
    });

    it('wants a real URL', async () => {
        renderCard();

        await shorten('not a url');

        expect(await screen.findByText(/Введіть повний URL/)).toBeInTheDocument();
        expect(sitesApi.createSite).not.toHaveBeenCalled();
    });

    it('says the workspace is full, with a way to fix it, when the plan has no room', async () => {
        vi.mocked(linksApi.createLink).mockRejectedValue(
            failure(402, {
                message: 'Ліміт плану «Free» вичерпано — посилань: 25.',
                code: 'plan_limit',
                resource: 'links',
                limit: 25,
                usage: 25,
                plan: 'free',
                workspace_id: 3,
            })
        );
        renderCard({ sites: [site] });

        await shorten();

        expect(await screen.findByText('Ліміт плану «Free» вичерпано — посилань: 25.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Переглянути тарифи' })).toHaveAttribute('href', '/workspaces/3/billing');
        expect(screen.queryByText('Готово — ось ваше перше посилання')).not.toBeInTheDocument();
    });

    it('copies the short URL', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        renderCard();
        await shorten();

        await userEvent.click(await screen.findByRole('button', { name: 'Копіювати посилання' }));

        expect(writeText).toHaveBeenCalledWith('https://go.example.com/abc123');
    });

    it('hands control back when the person is done', async () => {
        const { onFinished } = renderCard();
        await shorten();

        await userEvent.click(await screen.findByRole('button', { name: 'Готово' }));

        expect(onFinished).toHaveBeenCalledTimes(1);
    });
});
