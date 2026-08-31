import { expect, test } from '@playwright/test';

/**
 * THE SEED TEST — the pattern every other E2E spec in this repo is modelled on.
 *
 * What it deliberately demonstrates:
 *
 *  1. Role-based locators only. getByRole / getByLabel, scoped through the page's aria-labelled
 *     regions. No CSS selectors: they break on a Tailwind tweak while the user flow is unchanged.
 *  2. Waiting for state, never for time. This app renders server-side with no JavaScript, so a
 *     navigation is the state change — waitForURL and web-first assertions cover it. There is no
 *     legitimate use of waitForTimeout here.
 *  3. One self-contained cycle: teardown, setup, action, assertion, cleanup — in a single test.
 *  4. A name bound to what is at risk, not to the steps taken.
 *
 * Why THIS scenario: per test-plan.md §4, the browser layer exists for what feature tests cannot
 * reach — the survival of a real session across the whole path. Saving a basket and finding it
 * again after a reload crosses auth, CSRF, routing, the session store and the database in one go.
 * The §2 risks stay with the HTTP feature tests; see §7.
 */

const PRODUCT = 'Mleko 3,2% 1 l';

test('a saved basket survives the round trip from save to reload', async ({ page }) => {
    // Unique per run, so a re-run cannot collide with a row an earlier run left behind.
    const basketName = `E2E koszyk ${Date.now()}`;

    await page.goto('/koszyk');

    // Teardown-before-setup. The working basket lives in the session, which outlives a crashed
    // run, so the test guarantees its own starting state instead of assuming one.
    const clearBasket = page.getByRole('button', { name: 'Wyczyść koszyk' });
    if ((await clearBasket.count()) > 0) {
        await clearBasket.click();
    }

    const addProduct = page.getByRole('region', { name: 'Dodaj produkt' });
    await addProduct.getByLabel('Produkt').selectOption({ label: PRODUCT });
    await addProduct.getByLabel('Ilość').fill('2');
    await addProduct.getByRole('button', { name: 'Dodaj' }).click();

    const basket = page.getByRole('region', { name: 'Zawartość koszyka' });
    await expect(basket.getByRole('heading', { name: PRODUCT })).toBeVisible();

    await page.getByLabel('Zapisz ten koszyk pod nazwą').fill(basketName);
    await page.getByRole('button', { name: 'Zapisz' }).click();

    // The risk this test exists for: does the basket actually survive, or did it only look saved?
    await page.goto('/koszyki');
    const savedBasket = page.getByRole('listitem').filter({ hasText: basketName });
    await expect(savedBasket.getByRole('heading', { name: basketName })).toBeVisible();

    await page.reload();
    await expect(savedBasket.getByRole('heading', { name: basketName })).toBeVisible();
    // Not just the name — the contents have to come back too, or "saved" means nothing.
    await expect(savedBasket.getByText(PRODUCT)).toBeVisible();

    // Cleanup: the saved row, then the working basket. Both outlive the test otherwise.
    await savedBasket.getByRole('button', { name: 'Usuń' }).click();
    await expect(page.getByRole('listitem').filter({ hasText: basketName })).toHaveCount(0);

    await page.goto('/koszyk');
    const clearAfter = page.getByRole('button', { name: 'Wyczyść koszyk' });
    if ((await clearAfter.count()) > 0) {
        await clearAfter.click();
    }
});
