// @ts-check
const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8000';

module.exports = defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  // Démarre le serveur Laravel si aucun n'écoute déjà (ignoré si E2E_BASE_URL pointe vers XAMPP)
  webServer: process.env.E2E_BASE_URL ? undefined : {
    command: 'php artisan serve --host=127.0.0.1 --port=8000',
    url: baseURL,
    reuseExistingServer: true,
    timeout: 60_000,
  },
});
