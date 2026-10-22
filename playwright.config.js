// @ts-check
import { defineConfig } from '@playwright/test';

const isCI = !!process.env.CI;

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 45_000,
    retries: isCI ? 2 : 1,
    workers: isCI ? 2 : 1,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: 'http://127.0.0.1:8000',
        headless: isCI ? true : false,
        viewport: { width: 1400, height: 900 },
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        storageState: 'tests/e2e/.auth/admin.json',
    },
    projects: [
        // Setup runs first with no storageState
        {
            name: 'setup',
            testMatch: '**/auth.setup.js',
            use: { storageState: undefined },
        },
        // All other specs use the saved admin session
        {
            name: 'chromium',
            dependencies: ['setup'],
        },
    ],
});
