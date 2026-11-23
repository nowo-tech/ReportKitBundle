import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { frameShot } from './frame';
import { signIn } from './auth';

/** REQ-DEMO-013 — README screenshot (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Report Kit screenshots', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  test('overview — demo frame with the bundle status', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('demo-status')).toContainText('NowoReportKitBundle');
    await frameShot(page, resolve(outDir, 'overview.png'));
  });

  test('report list and report page', async ({ page }) => {
    await signIn(page, 'alice');
    await expect(page.getByTestId('report-kit-index')).toBeVisible();
    await frameShot(page, resolve(outDir, 'report-list.png'));
    await page.goto('/reports/signups');
    await expect(page.getByTestId('report-kit-chart')).toBeVisible();
    await frameShot(page, resolve(outDir, 'report-page.png'));
    await page.goto('/reports/orders_by_status');
    await expect(page.getByTestId('report-kit-section').first()).toBeVisible();
    await frameShot(page, resolve(outDir, 'report-orders.png'));
  });
});
