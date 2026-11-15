import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';

const portable = path.resolve('.runtime/php83/php.exe');
const php = process.env.BSHOP_PHP || (fs.existsSync(portable) ? portable : 'php');

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: true,
    workers: 2,
    timeout: 30000,
    expect: { timeout: 8000 },
    outputDir: 'test-results/tablet',
    reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
    use: {
        baseURL: 'http://127.0.0.1:8017',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        actionTimeout: 10000,
    },
    webServer: process.env.BSHOP_BROWSER_OFFLINE === '1' ? undefined : {
        command: `"${php}" artisan serve --host=127.0.0.1 --port=8017 --no-reload`,
        url: 'http://127.0.0.1:8017/up',
        reuseExistingServer: !process.env.CI,
        env: {
            APP_ENV: 'testing',
            APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            APP_DEBUG: 'false',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: ':memory:',
            SESSION_DRIVER: 'file',
            CACHE_STORE: 'array',
            MAIL_MAILER: 'array',
        },
    },
    projects: [
        { name: 'ipad-portrait-webkit', use: { ...devices['iPad Mini'], browserName: 'webkit', viewport: { width: 768, height: 1024 } } },
        { name: 'ipad-landscape-webkit', use: { ...devices['iPad Mini'], browserName: 'webkit', viewport: { width: 1024, height: 768 } } },
        { name: 'android-portrait-chromium', use: { browserName: 'chromium', hasTouch: true, isMobile: true, deviceScaleFactor: 2, viewport: { width: 800, height: 1280 } } },
        { name: 'tablet-wide-chromium', use: { browserName: 'chromium', hasTouch: true, isMobile: true, deviceScaleFactor: 2, viewport: { width: 1280, height: 800 } } },
        { name: 'desktop-chromium', testMatch: /(?:responsive|sales|sales-control|baskets|inventory-review|reservation-settings|customers|public-orders|weekly-accounts)\.spec\.ts/, use: { browserName: 'chromium', viewport: { width: 1440, height: 1000 } } },
        { name: 'mobile-webkit', testMatch: /(?:responsive|sales|sales-control|baskets|inventory-review|reservation-settings|customers|public-orders|weekly-accounts)\.spec\.ts/, use: { ...devices['iPhone 13'], browserName: 'webkit' } },
    ],
});
