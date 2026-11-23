import { test, expect, type Page } from '@playwright/test';
import { signIn } from './auth';

/**
 * REQ-DEMO-013 — S2 report pages: the list per viewer, the report page with its filters, an export in each format,
 * the 403 of a type the viewer may not open and the sign-in redirect for anonymous visitors. No console error.
 */
function watchErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(message.text());
    }
  });
  page.on('pageerror', (error) => errors.push(error.message));

  return errors;
}

test.describe('Report Kit pages', () => {
  test('anonymous visitors are sent to the sign-in', async ({ page }) => {
    await page.goto('/reports');
    await expect(page).toHaveURL(/\/\?signin=1$/);
    await expect(page.getByTestId('demo-sign-in')).toBeVisible();
  });

  test('alice filters a report and exports it in every format', async ({ page }) => {
    const errors = watchErrors(page);
    await signIn(page, 'alice');
    const list = page.getByTestId('report-kit-index');
    await expect(list).toContainText('Signups per day');
    await expect(list).toContainText('Orders by status');

    await list.getByRole('link', { name: 'Open' }).first().click();
    await expect(page).toHaveURL(/\/reports\/signups$/);
    await expect(page.getByTestId('report-kit-filters-line')).toContainText('Last 30 days');
    await expect(page.getByTestId('report-kit-section')).toHaveCount(2);
    await expect(page.getByTestId('report-kit-chart').locator('img').first()).toBeVisible();

    const filters = page.getByTestId('report-kit-filters');
    await filters.getByLabel('Period').selectOption('7');
    await filters.getByLabel('Context').selectOption({ label: 'Beta app' });
    await page.getByTestId('report-kit-filters-apply').click();
    await expect(page).toHaveURL(/period=7/);
    await expect(page.getByTestId('report-kit-filters-line')).toContainText('Last 7 days · Beta app');

    const types: Record<string, string> = {
      csv: 'text/csv; charset=UTF-8',
      xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      pdf: 'application/pdf',
    };
    for (const [format, type] of Object.entries(types)) {
      const href = await page.getByTestId(`report-kit-export-${format}`).getAttribute('href');
      expect(href).toContain('context=project:beta');
      const response = await page.request.get(href ?? '');
      expect(response.status(), format).toBe(200);
      expect(response.headers()['content-type']).toBe(type);
      expect(response.headers()['cache-control']).toContain('no-store');
      expect(response.headers()['cache-control']).toContain('private');
      expect(response.headers()['content-disposition']).toMatch(new RegExp(`^attachment; filename=demo-report-signups-beta-app-\\d{4}-\\d{2}-\\d{2}\\.${format}$`));
    }
    const csv = await (await page.request.get('/reports/orders_by_status/export.csv')).text();
    expect(csv).toContain("'=SUM(A1:A9) please");
    await page.waitForLoadState('networkidle');
    expect(errors).toEqual([]);
  });

  test('bob only sees what he may open', async ({ page }) => {
    await signIn(page, 'bob');
    const list = page.getByTestId('report-kit-index');
    await expect(list).toContainText('Signups per day');
    await expect(list).not.toContainText('Orders by status');

    const forbidden = await page.goto('/reports/orders_by_status');
    expect(forbidden?.status()).toBe(403);

    await page.goto('/reports/signups?context=project:alpha');
    await expect(page.getByTestId('report-kit-context-fallback')).toBeVisible();
    await expect(page.getByTestId('report-kit-filters-line')).toContainText('the requested context is not available');
  });
});
