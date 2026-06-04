import { test as setup, expect } from '@playwright/test';
import config from '../config';

const authFile = 'tests/playwright/.auth/login.json';

setup('authenticate as admin', async ({ page }) => {
  await page.goto(config.baseUrl + '/typo3/');
  await page.waitForLoadState('networkidle');

  // Use the stable field/button identifiers shared by the TYPO3 v12/v13/v14
  // login form (label/aria-label wording differs between versions).
  await page.locator('input[name="username"]').fill(config.admin.username);
  await page.locator('input[name="p_field"]').fill(config.admin.password);
  await page.locator('#t3-login-submit').click();

  // Wait for the backend to load.
  await page.waitForURL(/\/typo3\//);
  await page.waitForLoadState('networkidle');

  // Verify login succeeded — the TYPO3 backend renders a module menu.
  await expect(page.locator('[data-modulemenu-identifier]').first()).toBeVisible({ timeout: 15000 });

  await page.context().storageState({ path: authFile });
});
