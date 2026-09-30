import { createContext } from 'react';
import type { BillingConfig } from '../types';

export interface ConfigValue {
    /** False until the server has answered (or failed to) - "billing is off" is not yet known. */
    loaded: boolean;
    registrationEnabled: boolean;
    billing: BillingConfig;
}

/**
 * What to assume until the server has answered - and if it never does: open
 * registration, nothing for sale. Both are the self-hosted defaults.
 */
export const defaultConfig: ConfigValue = {
    loaded: false,
    registrationEnabled: true,
    billing: { enabled: false },
};

export const ConfigContext = createContext<ConfigValue>(defaultConfig);
