import type { Page } from '@playwright/test';

/** Wait until the Symfony Web Debug Toolbar has finished loading (optional outside dev). */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.waitForSelector('.sf-toolbar', { state: 'visible', timeout: 20000 }).catch(() => undefined);
  await page
    .waitForFunction(
      () => {
        const bar = document.querySelector('.sf-toolbar') as HTMLElement | null;
        return !bar || bar.offsetParent === null || !!document.querySelector('.sf-toolbar-block');
      },
      { timeout: 25000, polling: 250 },
    )
    .catch(() => undefined);
}
