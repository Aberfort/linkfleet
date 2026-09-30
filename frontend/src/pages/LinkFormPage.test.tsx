import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import LinkFormPage from './LinkFormPage';
import * as linksApi from '../api/links';
import type { Link } from '../types';
import { httpFailure, planFull } from '../test/http';

vi.mock('../api/links');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const existing: Link = {
    id: 10,
    site_id: 1,
    short_code: 'promo',
    target_url: 'https://example.com/page?ref=a%20b&utm_source=news&utm_campaign=spring#top',
    is_active: true,
    clicks_count: 0,
    expires_at: null,
    has_password: false,
    short_url: 'https://api.example.test/r/promo',
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

function renderNew() {
    return render(
        <MemoryRouter initialEntries={['/sites/1/links/new']}>
            <Routes>
                <Route path="/sites/:siteId/links/new" element={<LinkFormPage />} />
                <Route path="/sites/:siteId/links" element={<div>links list</div>} />
            </Routes>
        </MemoryRouter>
    );
}

function renderEdit() {
    return render(
        <MemoryRouter initialEntries={['/links/10/edit']}>
            <Routes>
                <Route path="/links/:linkId/edit" element={<LinkFormPage />} />
                <Route path="/sites/:siteId/links" element={<div>links list</div>} />
            </Routes>
        </MemoryRouter>
    );
}

describe('LinkFormPage UTM builder', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('writes the tags into the target URL as they are typed', async () => {
        renderNew();
        await userEvent.type(screen.getByLabelText('Цільовий URL'), 'https://example.com/page');

        await userEvent.click(screen.getByText('UTM-мітки'));
        await userEvent.type(screen.getByLabelText('Джерело (utm_source)'), 'newsletter');
        await userEvent.type(screen.getByLabelText('Канал (utm_medium)'), 'email');
        await userEvent.type(screen.getByLabelText('Кампанія (utm_campaign)'), 'spring sale');

        expect(screen.getByLabelText('Цільовий URL')).toHaveValue(
            'https://example.com/page?utm_source=newsletter&utm_medium=email&utm_campaign=spring%20sale'
        );
    });

    it('cannot tag a URL that is not a URL yet, and says why', async () => {
        renderNew();

        await userEvent.click(screen.getByText('UTM-мітки'));

        expect(screen.getByLabelText('Джерело (utm_source)')).toBeDisabled();
        expect(screen.getByText('Спершу введіть цільовий URL повністю, з https://.')).toBeInTheDocument();
    });

    it('shows the tags already in a URL that is typed or pasted by hand', async () => {
        renderNew();
        await userEvent.click(screen.getByText('UTM-мітки'));
        const url = screen.getByLabelText('Цільовий URL');

        await userEvent.type(url, 'https://example.com/?utm_source=twitter&utm_campaign=launch');

        expect(screen.getByLabelText('Джерело (utm_source)')).toHaveValue('twitter');
        expect(screen.getByLabelText('Кампанія (utm_campaign)')).toHaveValue('launch');
    });

    it('clearing a tag takes it out of the URL and leaves the rest', async () => {
        renderNew();
        await userEvent.type(screen.getByLabelText('Цільовий URL'), 'https://example.com/?a=1&utm_source=x&utm_medium=email');
        await userEvent.click(screen.getByText('UTM-мітки'));

        await userEvent.clear(screen.getByLabelText('Джерело (utm_source)'));

        expect(screen.getByLabelText('Цільовий URL')).toHaveValue('https://example.com/?a=1&utm_medium=email');
    });

    it('opens by itself for a link that already has tags, and leaves its other parameters and fragment alone', async () => {
        vi.mocked(linksApi.getLink).mockResolvedValue(existing);
        renderEdit();

        expect(await screen.findByLabelText('Джерело (utm_source)')).toHaveValue('news');
        expect(screen.getByLabelText('Кампанія (utm_campaign)')).toHaveValue('spring');

        await userEvent.clear(screen.getByLabelText('Джерело (utm_source)'));
        await userEvent.type(screen.getByLabelText('Джерело (utm_source)'), 'partner');

        expect(screen.getByLabelText('Цільовий URL')).toHaveValue(
            'https://example.com/page?ref=a%20b&utm_source=partner&utm_campaign=spring#top'
        );
    });

    it('stays closed for a link without tags', async () => {
        vi.mocked(linksApi.getLink).mockResolvedValue({ ...existing, target_url: 'https://example.com/plain' });
        renderEdit();
        await screen.findByLabelText('Цільовий URL');

        expect(screen.getByRole('button', { name: /UTM-мітки/ })).toHaveAttribute('aria-expanded', 'false');
    });

    it('saves the tagged URL', async () => {
        vi.mocked(linksApi.createLink).mockResolvedValue({ ...existing, id: 11 });
        renderNew();
        await userEvent.type(screen.getByLabelText('Цільовий URL'), 'https://example.com/page');
        await userEvent.click(screen.getByText('UTM-мітки'));
        await userEvent.type(screen.getByLabelText('Джерело (utm_source)'), 'newsletter');
        await userEvent.click(screen.getByRole('button', { name: 'Зберегти' }));

        await waitFor(() =>
            expect(linksApi.createLink).toHaveBeenCalledWith(
                1,
                expect.objectContaining({ target_url: 'https://example.com/page?utm_source=newsletter' })
            )
        );
        expect(await screen.findByText('links list')).toBeInTheDocument();
    });
});

describe('LinkFormPage when the plan is full', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    async function submitNew() {
        renderNew();
        await userEvent.type(screen.getByLabelText('Цільовий URL'), 'https://example.com/page');
        await userEvent.click(screen.getByRole('button', { name: /Зберегти|Додати/ }));
    }

    it('says so where it can be read, with a way to fix it, and stays on the form', async () => {
        vi.mocked(linksApi.createLink).mockRejectedValue(planFull(4));

        await submitNew();

        expect(await screen.findByText('Ліміт плану «Free» вичерпано — посилань: 25.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Переглянути тарифи' })).toHaveAttribute('href', '/workspaces/4/billing');
        expect(screen.queryByText('links list')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Цільовий URL')).toHaveValue('https://example.com/page'); // what was typed is kept
    });

    it('does not mistake another failure for a full plan', async () => {
        vi.mocked(linksApi.createLink).mockRejectedValue(httpFailure(500, { message: 'oops' }));

        await submitNew();

        await waitFor(() => expect(linksApi.createLink).toHaveBeenCalled());
        expect(screen.queryByRole('link', { name: 'Переглянути тарифи' })).not.toBeInTheDocument();
    });

    it('lets go of the notice once a later attempt goes through', async () => {
        vi.mocked(linksApi.createLink).mockRejectedValueOnce(planFull(4)).mockResolvedValueOnce(existing);

        await submitNew();
        await screen.findByRole('link', { name: 'Переглянути тарифи' });
        await userEvent.click(screen.getByRole('button', { name: /Зберегти|Додати/ }));

        expect(await screen.findByText('links list')).toBeInTheDocument();
    });
});
