<?php

namespace Tests\Feature\Basket;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A verdict must never outlive the basket it describes.
 *
 * Every basket write clears the comparison (BasketSession), so a report can never be shown
 * beside a basket it was not computed from. That is the PRD guardrail — "the verdict does not
 * lie" — applied to time rather than to data: a stale verdict is a wrong verdict.
 *
 * The disappearance also has to be explained, or it reads as the page losing the user's work
 * rather than as the app refusing to show a number it no longer stands behind.
 *
 * Kept here rather than in the browser suite: this is server-side session state rendered
 * server-side, so an HTTP test proves all of it — see the §7 boundary in test-plan.md.
 */
class StaleComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $slug = 'stale-test-product'): Product
    {
        return Product::factory()->create(['slug' => $slug]);
    }

    public function test_editing_the_basket_withdraws_the_report_and_says_why(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->actingAs($user)
            ->post(route('basket.store'), ['product' => $product->slug, 'quantity' => 1])
            ->assertRedirect(route('basket.index'));

        $this->actingAs($user)->post(route('basket.compare'));

        // The comparison is live at this point: the report section is rendered.
        $this->actingAs($user)->get(route('basket.index'))
            ->assertOk()
            ->assertSee('Wynik porównania');

        // Any write to the basket invalidates it — here, a quantity change.
        $this->actingAs($user)->patch(route('basket.update', $product->slug), ['quantity' => 2]);

        $afterEdit = $this->actingAs($user)->get(route('basket.index'));

        $afterEdit->assertOk();
        // The report is gone...
        $afterEdit->assertDontSee('Wynik porównania');
        // ...and its absence is accounted for, rather than looking like lost work.
        $afterEdit->assertSee('Koszyk się zmienił', false);
    }

    public function test_the_explanation_is_not_shown_when_nothing_was_discarded(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        // Adding to a basket that was never compared discards nothing, so there is nothing to
        // explain. Without this case the assertion above would also pass on a notice that is
        // simply always on.
        $this->actingAs($user)
            ->post(route('basket.store'), ['product' => $product->slug, 'quantity' => 1]);

        $this->actingAs($user)->get(route('basket.index'))
            ->assertOk()
            ->assertDontSee('Koszyk się zmienił', false);
    }
}
