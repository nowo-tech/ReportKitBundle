import { test, expect } from '@playwright/test';

/** REQ-DEMO-013 — S0 scaffold: the home page renders on the UiKit shell without console errors. */
test.describe('Report Kit demo', () => {
  test('home page renders the bundle status and logs no console error', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        errors.push(message.text());
      }
    });
    page.on('pageerror', (error) => errors.push(error.message));

    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page.getByTestId('demo-masthead')).toBeVisible();
    await expect(page.getByTestId('demo-status')).toContainText('NowoReportKitBundle');
    await expect(page.getByTestId('demo-status')).toContainText('worker mode');
    await page.waitForLoadState('networkidle');
    expect(errors).toEqual([]);
  });
});
