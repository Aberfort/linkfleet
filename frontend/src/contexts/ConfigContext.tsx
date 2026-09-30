import React, { useEffect, useState } from 'react';
import { fetchAppConfig } from '../api/config';
import { ConfigContext, defaultConfig } from './configValue';
import type { ConfigValue } from './configValue';

/**
 * Fetched once at startup, so a deployment can close registration or turn
 * billing on without a frontend rebuild (see ConfigController on the backend).
 */
export const ConfigProvider = ({ children }: { children: React.ReactNode }) => {
    const [config, setConfig] = useState<ConfigValue>(defaultConfig);

    useEffect(() => {
        fetchAppConfig()
            .then((remote) =>
                setConfig({
                    loaded: true,
                    registrationEnabled: remote.registration_enabled,
                    billing: remote.billing ?? defaultConfig.billing,
                })
            )
            .catch(() => setConfig({ ...defaultConfig, loaded: true }));
    }, []);

    return <ConfigContext.Provider value={config}>{children}</ConfigContext.Provider>;
};
