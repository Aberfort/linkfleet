import { useContext } from 'react';
import { ConfigContext } from './configValue';

export function useConfig() {
    return useContext(ConfigContext);
}
