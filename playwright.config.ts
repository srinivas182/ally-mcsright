import { defineConfig, devices } from '@playwright/test';

/*
 * E2E1 (specs/core/15): the main journeys in a real browser against a real server and database. The nightly run
 * (.github/workflows/e2e-nightly.yml) uses MySQL; locally it uses whatever .env points at. The brokerage comes from
 * `php artisan e2e:seed`. Tenant and website addresses are mapped to the local server, so cookies and tenant
 * lookups work exactly as in production.
 */
// Port 80: the app builds its own links (and asset links) without a port, as in production.
const PORT = Number(process.env.E2E_PORT ?? 80);

export default defineConfig({
    testDir: './e2e',
    fullyParallel: false,
    workers: 1, // one PHP server
    retries: process.env.CI ? 1 : 0,
    timeout: 120_000, // an owner sign-in may wait for the next two-step code
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        launchOptions: {
            args: [
                `--host-resolver-rules=MAP *.rightally.app 127.0.0.1, MAP *.rightally.site 127.0.0.1, MAP app.rightally.io 127.0.0.1`,
            ],
        },
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'phone', use: { ...devices['Pixel 7'] }, grep: /@phone/ },
    ],
    webServer: process.env.E2E_NO_SERVER
        ? undefined
        : {
              command: `php artisan serve --host=127.0.0.1 --port=${PORT}`,
              url: `http://127.0.0.1:${PORT}/up`,
              reuseExistingServer: !process.env.CI,
              timeout: 120_000, // an owner sign-in may wait for the next two-step code
          },
});
