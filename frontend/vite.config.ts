/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [react()],
    server: {
        port: 3000,
        // The conversion snippet is served by the backend but tested here (see
        // src/snippet); this lets the tests read that one directory.
        fs: { allow: ['.', '../backend/public'] },
    },
    preview: {
        port: 3000,
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./src/test/setup.ts'],
    },
});
