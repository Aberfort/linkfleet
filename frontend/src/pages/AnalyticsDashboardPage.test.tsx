import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route, useLocation } from 'react-router-dom';
import AnalyticsDashboardPage from './AnalyticsDashboardPage';
import * as analyticsApi from '../api/analytics';
import * as download from '../utils/download';
import type { Analytics } from '../types';

vi.mock('../api/analytics');
vi.mock('../utils/download');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

// jsdom has no layout, so real charts render nothing. Stand-ins expose the
// series they were given, which is what these tests are about.
interface Series {
    label: string;
    data: number[];
}
vi.mock('@mui/x-charts/LineChart', () => ({
    LineChart: ({ series }: { series: Series[] }) => (
        <div data-testid="line-chart">{series.map((s) => `${s.label}:${s.data.join(',')}`).join(' | ')}</div>
    ),
}));
vi.mock('@mui/x-charts/BarChart', () => ({ BarChart: () => <div data-testid="bar-chart" /> }));

const base: Analytics = {
    range: { from: '2026-09-01', to: '2026-09-30', days: 30 },
    totals: { clicks: 120, visitors: 45 },
    timeseries: [
        { date: '2026-09-29', clicks: 5 },
        { date: '2026-09-30', clicks: 7 },
    ],
    referrers: [{ label: 'twitter.com', clicks: 9 }],
    browsers: [],
    devices: [],
    top_links: [
        { id: 1, short_code: 'promo', short_url: 'https://api.example.test/r/promo', target_url: 'https://example.com/promo', clicks_count: 500, period_clicks: 42 },
    ],
};

const compared: Analytics = {
    ...base,
    previous: {
        range: { from: '2026-08-02', to: '2026-08-31', days: 30 },
        totals: { clicks: 80, visitors: 60 },
        timeseries: [
            { date: '2026-08-30', clicks: 3 },
            { date: '2026-08-31', clicks: 4 },
        ],
    },
};

function Location() {
    const location = useLocation();

    return <div data-testid="location">{location.search}</div>;
}

function renderPage(initial = '/sites/3/analytics') {
    return render(
        <MemoryRouter initialEntries={[initial]}>
            <Location />
            <Routes>
                <Route path="/sites/:siteId/analytics" element={<AnalyticsDashboardPage />} />
                <Route path="/links/:linkId/analytics" element={<AnalyticsDashboardPage />} />
            </Routes>
        </MemoryRouter>
    );
}

describe('AnalyticsDashboardPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(analyticsApi.fetchAnalytics).mockResolvedValue(base);
    });

    it('opens on the servers default window and shows the totals', async () => {
        renderPage();

        expect(await screen.findByText('120')).toBeInTheDocument();
        expect(screen.getByText('45')).toBeInTheDocument();
        expect(analyticsApi.fetchAnalytics).toHaveBeenCalledWith({ kind: 'site', id: 3 }, { compare: false });
        expect(screen.getByText(/2026-09-01 — 2026-09-30, 30 дн\./)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '30 днів' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('asks for a link by its own route', async () => {
        renderPage('/links/9/analytics');

        await screen.findByText('120');

        expect(analyticsApi.fetchAnalytics).toHaveBeenCalledWith({ kind: 'link', id: 9 }, { compare: false });
    });

    it('a preset asks the server for N days, and keeps the choice in the address', async () => {
        renderPage();
        await screen.findByText('120');

        await userEvent.click(screen.getByRole('button', { name: '7 днів' }));

        await waitFor(() =>
            expect(analyticsApi.fetchAnalytics).toHaveBeenLastCalledWith({ kind: 'site', id: 3 }, { days: 7, compare: false })
        );
        expect(screen.getByTestId('location')).toHaveTextContent('?days=7');
        expect(screen.getByRole('button', { name: '7 днів' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('restores the range from the address, so a link to a report shows the same report', async () => {
        renderPage('/sites/3/analytics?days=90&compare=previous');
        vi.mocked(analyticsApi.fetchAnalytics).mockResolvedValue(compared);

        await screen.findByText('120');

        expect(analyticsApi.fetchAnalytics).toHaveBeenCalledWith({ kind: 'site', id: 3 }, { days: 90, compare: true });
        expect(screen.getByRole('button', { name: '90 днів' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('checkbox', { name: /Порівняти/ })).toBeChecked();
    });

    it('a custom range is sent as explicit dates and un-presses the presets', async () => {
        renderPage();
        await screen.findByText('120');

        const from = screen.getByLabelText('Від');
        await userEvent.clear(from);
        await userEvent.type(from, '2026-09-10');

        await waitFor(() =>
            expect(analyticsApi.fetchAnalytics).toHaveBeenLastCalledWith(
                { kind: 'site', id: 3 },
                { from: '2026-09-10', to: '2026-09-30', compare: false }
            )
        );
        expect(screen.getByTestId('location')).toHaveTextContent('from=2026-09-10');
        expect(screen.getByTestId('location')).not.toHaveTextContent('days=');
        expect(screen.getByRole('button', { name: '30 днів' })).toHaveAttribute('aria-pressed', 'false');
    });

    it('will not choose a start date in the future of what the server allows', async () => {
        renderPage();
        await screen.findByText('120');

        expect(screen.getByLabelText('До')).toHaveAttribute('max', '2026-09-30');
        expect(screen.getByLabelText('Від')).toHaveAttribute('max', '2026-09-30');
    });

    it('shows the servers objection to a range instead of a generic failure', async () => {
        vi.mocked(analyticsApi.fetchAnalytics).mockRejectedValueOnce(
            Object.assign(new (await import('axios')).AxiosError('bad'), {
                response: { status: 422, data: { message: 'x', errors: { to: ['Період не може бути довшим за 366 днів.'] } } },
            })
        );

        renderPage('/sites/3/analytics?from=2020-01-01&to=2026-09-01');

        expect(await screen.findByText('Період не може бути довшим за 366 днів.')).toBeInTheDocument();
    });

    it('compares with the previous period: deltas on both totals and a second line on the chart', async () => {
        vi.mocked(analyticsApi.fetchAnalytics).mockResolvedValue(compared);
        renderPage('/sites/3/analytics?compare=previous');

        await screen.findByText('120');

        // clicks 80 -> 120 is +50%; visitors 60 -> 45 is -25%
        expect(screen.getByText('+50%')).toBeInTheDocument();
        expect(screen.getByText('−25%')).toBeInTheDocument();
        expect(screen.getByTestId('line-chart')).toHaveTextContent('Цей період:5,7 | Попередній період:3,4');
        expect(screen.getByText(/попередній період: 2026-08-02 — 2026-08-31/)).toBeInTheDocument();
    });

    it('without comparison there are no deltas and only one line', async () => {
        renderPage();
        await screen.findByText('120');

        expect(screen.queryByText('+50%')).not.toBeInTheDocument();
        expect(screen.getByTestId('line-chart')).toHaveTextContent('Цей період:5,7');
        expect(screen.getByTestId('line-chart')).not.toHaveTextContent('Попередній');
    });

    it('turning comparison on asks again, with it', async () => {
        renderPage();
        await screen.findByText('120');

        await userEvent.click(screen.getByRole('checkbox', { name: /Порівняти/ }));

        await waitFor(() =>
            expect(analyticsApi.fetchAnalytics).toHaveBeenLastCalledWith({ kind: 'site', id: 3 }, { compare: true })
        );
        expect(screen.getByTestId('location')).toHaveTextContent('compare=previous');
    });

    it('ranks the top links by the period and still shows their all-time total', async () => {
        renderPage();

        expect(await screen.findByText('42')).toBeInTheDocument();
        expect(screen.getByText('500')).toBeInTheDocument();
        expect(screen.getByText('За період')).toBeInTheDocument();
    });

    it('exports what is on screen and saves the file', async () => {
        const blob = new Blob(['a,b']);
        vi.mocked(analyticsApi.exportAnalytics).mockResolvedValue({ blob, filename: 'linkfleet-shop-2026-09-01_2026-09-30.csv' });
        renderPage('/sites/3/analytics?from=2026-09-01&to=2026-09-30&compare=previous');
        await screen.findByText('120');

        await userEvent.click(screen.getByRole('button', { name: 'Експорт CSV' }));

        await waitFor(() =>
            expect(download.saveBlob).toHaveBeenCalledWith(blob, 'linkfleet-shop-2026-09-01_2026-09-30.csv')
        );
        expect(analyticsApi.exportAnalytics).toHaveBeenCalledWith(
            { kind: 'site', id: 3 },
            { from: '2026-09-01', to: '2026-09-30', compare: true }
        );
    });

    it('does not save anything when the export fails', async () => {
        vi.mocked(analyticsApi.exportAnalytics).mockRejectedValue(new Error('boom'));
        renderPage();
        await screen.findByText('120');

        await userEvent.click(screen.getByRole('button', { name: 'Експорт CSV' }));

        await waitFor(() => expect(analyticsApi.exportAnalytics).toHaveBeenCalled());
        expect(download.saveBlob).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Експорт CSV' })).toBeEnabled();
    });
});
