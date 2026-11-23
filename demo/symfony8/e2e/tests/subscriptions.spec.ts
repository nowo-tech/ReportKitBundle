import { test, expect, type Page } from '@playwright/test';
import { signIn } from './auth';

/**
 * REQ-DEMO-013 — S3 scheduled e-mail subscriptions: the lazy *Schedule by e-mail* dialog (the form is loaded on open,
 * saved → back to the list), the list with pause / resume / delete in the same dialog, and the Mailpit check of the
 * scheduled mail (sent to the owner only, with the files). No console error.
 */
const mailpit = process.env.MAILPIT_URL || 'http://127.0.0.1:8224';

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

/** Deletes every subscription of the signed-in user through the full pages (the no-JavaScript path of the dialog). */
async function clearSubscriptions(page: Page): Promise<void> {
  for (let i = 0; i < 10; i++) {
    await page.goto('/reports');
    const remove = page.getByTestId('report-kit-subscription-delete').first();
    if ((await remove.count()) === 0) {
      return;
    }
    const href = await remove.getAttribute('href');
    await page.goto(href ?? '/reports');
    await page.getByTestId('report-kit-subscription-delete').getByRole('button', { name: 'Delete' }).click();
    await expect(page).toHaveURL(/\/reports$/);
  }
}

test.describe('Report Kit subscriptions', () => {
  test('alice schedules a report in the dialog, receives it, pauses and deletes it', async ({ page, request }) => {
    const errors = watchErrors(page);
    await signIn(page, 'alice');
    await clearSubscriptions(page);
    await request.delete(`${mailpit}/api/v1/messages`);

    // The form is not in the page: it is loaded when the dialog opens.
    await page.goto('/reports/signups?period=7&context=project:beta');
    await expect(page.locator('form[name="schedule"]')).toHaveCount(0);
    await page.getByTestId('report-kit-schedule-open').click();
    const dialog = page.getByTestId('report-kit-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Frequency')).toBeVisible();

    await dialog.getByLabel('Frequency').selectOption('daily');
    await dialog.getByLabel('Hour').selectOption('7');
    await dialog.getByLabel('CSV file').check();
    await dialog.getByRole('button', { name: 'Schedule' }).click();

    // Saved: 204 + X-Nowo-Redirect → the list with the new row.
    await expect(page).toHaveURL(/\/reports$/);
    const table = page.getByTestId('report-kit-subscriptions');
    await expect(table).toContainText('Signups per day');
    await expect(table).toContainText('Every day at 07:00 (Europe/Madrid)');
    await expect(table).toContainText('Active');
    await expect(page.locator('[data-testid="report-kit-subscriptions"] form')).toHaveCount(0);

    // Send the due reports now (demo button) and read the mail in Mailpit.
    await page.goto('/');
    await page.getByTestId('demo-run-due-submit').click();
    await expect(page).toHaveURL(/\/reports$/);
    await expect(page.getByTestId('report-kit-subscriptions')).toContainText('last sent');
    await expect
      .poll(async () => (await (await request.get(`${mailpit}/api/v1/messages`)).json()).total, { timeout: 15000 })
      .toBe(1);
    const list = await (await request.get(`${mailpit}/api/v1/messages`)).json();
    const summary = list.messages[0];
    expect(summary.To.map((to: { Address: string }) => to.Address)).toEqual(['alice@demo.test']);
    expect(summary.Cc ?? []).toEqual([]);
    expect(summary.Bcc ?? []).toEqual([]);
    expect(summary.Subject).toBe('Your report: Signups per day');
    const message = await (await request.get(`${mailpit}/api/v1/message/${summary.ID}`)).json();
    const files: string[] = message.Attachments.map((a: { FileName: string }) => a.FileName);
    expect(files.some((name) => name.endsWith('.pdf'))).toBe(true);
    expect(files.some((name) => name.endsWith('.csv'))).toBe(true);
    expect(message.HTML).toContain('/reports/signups?period=7');
    expect(message.HTML).toContain('context=project:beta');

    // Pause in the dialog, then delete.
    await page.getByTestId('report-kit-subscription-toggle').first().click();
    await expect(dialog.getByRole('button', { name: 'Pause' })).toBeVisible();
    await dialog.getByRole('button', { name: 'Pause' }).click();
    await expect(page).toHaveURL(/\/reports$/);
    await expect(page.getByTestId('report-kit-subscriptions')).toContainText('Paused by you');
    await clearSubscriptions(page);
    await expect(page.getByTestId('report-kit-subscriptions-empty')).toBeVisible();

    expect(errors).toEqual([]);
  });

  test("bob cannot open alice's subscription pages", async ({ page }) => {
    await signIn(page, 'alice');
    await clearSubscriptions(page);
    await page.goto('/reports/signups/schedule?period=30');
    await page.getByRole('button', { name: 'Schedule' }).click();
    await expect(page).toHaveURL(/\/reports$/);
    const href = await page.getByTestId('report-kit-subscription-toggle').first().getAttribute('href');
    await page.goto('/sign-out');

    await signIn(page, 'bob');
    const response = await page.goto(href ?? '/');
    expect(response?.status()).toBe(404);

    await page.goto('/sign-out');
    await signIn(page, 'alice');
    await clearSubscriptions(page);
  });
});
