import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ConfigProvider } from './ConfigContext';
import { useConfig } from './useConfig';
import * as configApi from '../api/config';

vi.mock('../api/config');

function Probe() {
    const { loaded, registrationEnabled, billing } = useConfig();

    return (
        <div>
            <span>loaded:{String(loaded)}</span>
            <span>registration:{String(registrationEnabled)}</span>
            <span>billing:{String(billing.enabled)}</span>
            <span>token:{billing.client_side_token ?? 'none'}</span>
        </div>
    );
}

function renderProbe() {
    return render(
        <ConfigProvider>
            <Probe />
        </ConfigProvider>
    );
}

describe('ConfigProvider', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('assumes a self-hosted install until the server has answered', () => {
        vi.mocked(configApi.fetchAppConfig).mockReturnValue(new Promise(() => undefined));

        renderProbe();

        expect(screen.getByText('loaded:false')).toBeInTheDocument();
        expect(screen.getByText('registration:true')).toBeInTheDocument();
        expect(screen.getByText('billing:false')).toBeInTheDocument();
    });

    it('takes what the server says', async () => {
        vi.mocked(configApi.fetchAppConfig).mockResolvedValue({
            registration_enabled: false,
            custom_domain_target: 'edge.example.net',
            billing: { enabled: true, client_side_token: 'tok', sandbox: true },
        });

        renderProbe();

        expect(await screen.findByText('loaded:true')).toBeInTheDocument();
        expect(screen.getByText('registration:false')).toBeInTheDocument();
        expect(screen.getByText('billing:true')).toBeInTheDocument();
        expect(screen.getByText('token:tok')).toBeInTheDocument();
    });

    it('treats a server that has never heard of billing as one that sells nothing', async () => {
        vi.mocked(configApi.fetchAppConfig).mockResolvedValue({
            registration_enabled: true,
            custom_domain_target: 'edge.example.net',
        } as never);

        renderProbe();

        expect(await screen.findByText('loaded:true')).toBeInTheDocument();
        expect(screen.getByText('billing:false')).toBeInTheDocument();
    });

    it('falls back to the defaults, and says it is done waiting, when the server cannot be reached', async () => {
        vi.mocked(configApi.fetchAppConfig).mockRejectedValue(new Error('offline'));

        renderProbe();

        expect(await screen.findByText('loaded:true')).toBeInTheDocument();
        expect(screen.getByText('registration:true')).toBeInTheDocument();
        expect(screen.getByText('billing:false')).toBeInTheDocument();
    });

    it('gives components outside any provider the same self-hosted defaults', () => {
        render(<Probe />);

        expect(screen.getByText('loaded:false')).toBeInTheDocument();
        expect(screen.getByText('billing:false')).toBeInTheDocument();
    });
});
