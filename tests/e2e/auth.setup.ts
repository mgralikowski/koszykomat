import { expect, test as setup } from '@playwright/test';

const storageState = 'playwright/.auth/user.json';

/**
 * Mints the session every other spec starts from.
 *
 * Authentication is OAuth-only (PRD FR-002) and Playwright cannot drive Google, so the session
 * comes from the local-only door at /_test/login. That door is registered only when the
 * environment is `local` and is pinned shut everywhere else by
 * tests/Feature/Auth/TestLoginRouteTest.php.
 *
 * No spec logs in through the UI: that is what storageState is for.
 */
setup('authenticate', async ({ page }) => {
    await page.goto('/_test/login');
    await page.waitForURL('**/koszyk');

    // Assert the session is real rather than trusting the redirect: the logout control only
    // renders for an authenticated user (layouts/app.blade.php).
    await expect(page.getByRole('button', { name: 'Wyloguj' })).toBeVisible();

    await page.context().storageState({ path: storageState });
});
