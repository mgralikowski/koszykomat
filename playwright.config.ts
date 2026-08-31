import { defineConfig, devices } from '@playwright/test';

// Playwright runs on the HOST, against the app served by ddev — not inside the web container.
// See the browser-layer note in context/foundation/test-plan.md §4.
const baseURL = process.env.E2E_BASE_URL ?? 'https://koszykomat.ddev.site';

const storageState = 'playwright/.auth/user.json';

export default defineConfig({
    testDir: './tests/e2e',

    // The working basket lives in the SERVER session, and every browser context restored from the
    // same storageState carries the same session cookie — so parallel tests would add products to
    // one another's basket and fail in ways that have nothing to do with the risk under test.
    // One worker until auth.setup mints a separate user per worker.
    workers: 1,
    fullyParallel: false,

    forbidOnly: !!process.env.CI,
    reporter: [['list']],

    use: {
        baseURL,
        // ddev serves a locally-signed certificate and the host does not trust its CA by default.
        ignoreHTTPSErrors: true,
        trace: 'on-first-retry',
    },

    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'desktop',
            use: { ...devices['Desktop Chrome'], storageState },
            dependencies: ['setup'],
        },
        {
            // Mobile-first is a PRD NFR ("the whole flow is fully usable on a phone") and one of
            // the two reasons this browser layer exists at all (test-plan.md §4). Running the same
            // specs at phone width is the cheapest way to keep that claim honest.
            name: 'mobile',
            use: { ...devices['Pixel 5'], storageState },
            dependencies: ['setup'],
        },
    ],
});
