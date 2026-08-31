import { expect, test } from '@playwright/test';

/**
 * Risk: the PRD's mobile-first NFR — "the whole flow (login → basket → report) is fully usable
 * on a phone" — silently stops holding. Per test-plan.md §4 this is one of the two reasons the
 * browser layer exists: no PHPUnit feature test can see a viewport, so nothing else can catch it.
 *
 * Runs in the `mobile` project only (playwright.config.ts testIgnore); at desktop width these
 * assertions pass trivially and would read as coverage without being any.
 *
 * Deliberately independent of price data. Leaflets expire, so on any given day the verdict may
 * be a real winner or "Brak danych" — asserting either would make this test fail by calendar
 * rather than by regression. The layout invariant holds regardless, and the per-product details
 * render both chains side by side either way, which is the widest content on the page.
 *
 * Modelled on seed.spec.ts.
 */

const PRODUCT = 'Mleko 3,2% 1 l';

/** The horizontal-overflow check, as a page-level invariant. 1px of slack absorbs sub-pixel rounding. */
async function documentOverflowsHorizontally(page: import('@playwright/test').Page) {
    return page.evaluate(() => {
        const doc = document.documentElement;

        return doc.scrollWidth > doc.clientWidth + 1;
    });
}

test('the basket and its comparison report stay usable at phone width', async ({ page }) => {
    await page.goto('/koszyk');

    // Teardown-before-setup: the working basket lives in the session and outlives a crashed run.
    const clearBasket = page.getByRole('button', { name: 'Wyczyść koszyk' });
    if ((await clearBasket.count()) > 0) {
        await clearBasket.click();
    }

    expect(await documentOverflowsHorizontally(page)).toBe(false);

    // A quantity above 1 so the report renders its conditional-promo wording, which is the
    // longest copy the details block can produce.
    const addProduct = page.getByRole('region', { name: 'Dodaj produkt' });
    await addProduct.getByLabel('Produkt').selectOption({ label: PRODUCT });
    await addProduct.getByLabel('Ilość').fill('3');
    await addProduct.getByRole('button', { name: 'Dodaj' }).click();

    await expect(page.getByRole('region', { name: 'Zawartość koszyka' })).toBeVisible();

    const compare = page.getByRole('button', { name: 'Porównaj' });
    await expect(compare).toBeVisible();
    await compare.click();

    // The verdict must be on screen, not merely in the DOM — a report the user has to scroll
    // sideways to read is exactly the failure this NFR forbids.
    const report = page.getByRole('region', { name: 'Wynik porównania' });
    await expect(report).toBeVisible();
    await expect(report.getByRole('region', { name: 'Werdykt' })).toBeVisible();

    // Both chains are named in the details block whether or not prices are available today.
    const details = report.getByRole('region', { name: 'Szczegóły koszyka' });
    await expect(details.getByText('Lidl').first()).toBeVisible();
    await expect(details.getByText('Biedronka').first()).toBeVisible();

    // The invariant, asserted with the widest content the page can hold actually rendered.
    expect(await documentOverflowsHorizontally(page)).toBe(false);

    // Cleanup.
    const clearAfter = page.getByRole('button', { name: 'Wyczyść koszyk' });
    if ((await clearAfter.count()) > 0) {
        await clearAfter.click();
    }
});
