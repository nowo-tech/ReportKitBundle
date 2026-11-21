import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { frameShot } from './frame';

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
});
