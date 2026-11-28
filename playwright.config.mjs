import { defineConfig } from '@playwright/test';

const artifacts = process.env.SDP_ARTIFACT_DIR ?? 'artifacts';

export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: false,
    workers: 1,
    outputDir: `${artifacts}/playwright`,
    reporter: [['list'], ['json', { outputFile: `${artifacts}/reports/browser.json` }]],
    use: {
        baseURL: process.env.SDP_BROWSER_URL ?? 'http://127.0.0.1:3000',
        viewport: { width: 1440, height: 900 },
        launchOptions: { executablePath: process.env.SDP_CHROMIUM },
        trace: 'off',
    },
});
