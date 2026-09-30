import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Navbar from './Navbar';
import { useAuth } from '../contexts/useAuth';
import { useConfig } from '../contexts/useConfig';

vi.mock('../contexts/useAuth');
vi.mock('../contexts/useConfig');
vi.mock('react-toastify', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const config = { loaded: true, registrationEnabled: true, billing: { enabled: false } };

function as(user: { is_demo: boolean } | null) {
    vi.mocked(useAuth).mockReturnValue({
        user: user && { id: 1, name: 'Olga', email: 'o@example.com', ...user },
        loading: false,
        login: vi.fn(),
        register: vi.fn(),
        logout: vi.fn(),
    });
}

function renderBar() {
    return render(
        <MemoryRouter>
            <Navbar />
        </MemoryRouter>
    );
}

describe('Navbar', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useConfig).mockReturnValue(config);
    });

    it('has no pricing link where nothing is sold', () => {
        as(null);
        renderBar();

        expect(screen.queryByRole('link', { name: 'Тарифи' })).not.toBeInTheDocument();
    });

    it('links the price list, signed in or not, where plans exist', () => {
        vi.mocked(useConfig).mockReturnValue({ ...config, billing: { enabled: true } });

        as(null);
        const { unmount } = renderBar();
        expect(screen.getByRole('link', { name: 'Тарифи' })).toHaveAttribute('href', '/pricing');
        unmount();

        as({ is_demo: false });
        renderBar();
        expect(screen.getByRole('link', { name: 'Тарифи' })).toHaveAttribute('href', '/pricing');
    });

    it('offers registration only while it is open', () => {
        as(null);
        const { unmount } = renderBar();
        expect(screen.getByRole('link', { name: 'Реєстрація' })).toBeInTheDocument();
        unmount();

        vi.mocked(useConfig).mockReturnValue({ ...config, registrationEnabled: false });
        renderBar();
        expect(screen.queryByRole('link', { name: 'Реєстрація' })).not.toBeInTheDocument();
    });
});
