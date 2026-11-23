import { expect, type Page } from '@playwright/test';

/** Demo sign-in (no password, demo only): the home page FormKit form picks the user and lands on /reports. */
export async function signIn(page: Page, user: 'alice' | 'bob'): Promise<void> {
  await page.goto('/');
  await page.getByTestId('demo-sign-in').getByLabel('Demo user').selectOption(user);
  await page.getByTestId('demo-sign-in-submit').click();
  await expect(page).toHaveURL(/\/reports$/);
}
