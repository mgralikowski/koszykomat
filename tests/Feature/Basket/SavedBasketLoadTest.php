<?php

namespace Tests\Feature\Basket;

use App\Basket\BasketSession;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The save→load round trip has to reproduce the basket exactly (FR-005).
 *
 * Quantities are not cosmetic here. Every conditional mechanic in FR-007 keys off them — 1+1
 * gratis, drugi za grosz and the conditional unit price all change the total based on how many
 * items are in the line — so a quantity that silently collapsed to the default is a wrong verdict,
 * not a display bug.
 *
 * The oracle is the basket the test saved, written out literally, never a value read back from the
 * loader. A test that asserted "what came out equals what toBasketLines() returns" would pass
 * against a loader that dropped every quantity.
 */
class SavedBasketLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_loading_restores_exactly_the_products_and_quantities_that_were_saved(): void
    {
        $user = User::factory()->create();

        $milk = Product::factory()->create(['slug' => 'mleko', 'name' => 'Mleko']);
        $butter = Product::factory()->create(['slug' => 'maslo', 'name' => 'Masło']);
        $chocolate = Product::factory()->create(['slug' => 'czekolada', 'name' => 'Czekolada']);

        $basket = $user->savedBaskets()->create(['name' => 'Zakupy']);
        $basket->items()->create(['product_id' => $milk->id, 'quantity' => 2]);
        $basket->items()->create(['product_id' => $butter->id, 'quantity' => 1]);
        $basket->items()->create(['product_id' => $chocolate->id, 'quantity' => 4]);

        // Something else entirely in the session, so a loader that merged instead of replacing
        // would leave a trace.
        $response = $this->actingAs($user)
            ->withSession(['basket.lines' => ['kawa' => 7]])
            ->post(route('saved.load', $basket->id));

        $response->assertRedirect(route('basket.index'));

        $this->assertSame(
            [
                ['product' => 'mleko', 'quantity' => 2],
                ['product' => 'maslo', 'quantity' => 1],
                ['product' => 'czekolada', 'quantity' => 4],
            ],
            $this->sessionBasketLines(),
        );
    }

    public function test_loading_replaces_rather_than_merges_the_working_basket(): void
    {
        $user = User::factory()->create();
        $milk = Product::factory()->create(['slug' => 'mleko']);

        $basket = $user->savedBaskets()->create(['name' => 'Zakupy']);
        $basket->items()->create(['product_id' => $milk->id, 'quantity' => 2]);

        // The same product already in the basket at a different quantity: a merge would produce 5.
        $this->actingAs($user)
            ->withSession(['basket.lines' => ['mleko' => 3]])
            ->post(route('saved.load', $basket->id));

        $this->assertSame(
            [['product' => 'mleko', 'quantity' => 2]],
            $this->sessionBasketLines(),
        );
    }

    public function test_loading_discards_a_comparison_of_the_previous_basket(): void
    {
        $user = User::factory()->create();
        $milk = Product::factory()->create(['slug' => 'mleko']);

        $basket = $user->savedBaskets()->create(['name' => 'Zakupy']);
        $basket->items()->create(['product_id' => $milk->id, 'quantity' => 2]);

        $this->actingAs($user)
            ->withSession(['basket.lines' => ['kawa' => 1], 'basket.compared' => true])
            ->post(route('saved.load', $basket->id));

        // A verdict computed from the previous basket must not survive alongside the new one.
        $this->assertFalse(session()->get('basket.compared', false));
    }

    public function test_a_stored_quantity_above_the_current_cap_loads_clamped(): void
    {
        $user = User::factory()->create();
        $milk = Product::factory()->create(['slug' => 'mleko']);

        $basket = $user->savedBaskets()->create(['name' => 'Zakupy']);
        // Written past the cap directly: the basket was saved when the cap was higher.
        $basket->items()->create(['product_id' => $milk->id, 'quantity' => 90]);

        config(['koszykomat.basket.max_quantity' => 10]);

        $this->actingAs($user)->post(route('saved.load', $basket->id));

        $this->assertSame(
            [['product' => 'mleko', 'quantity' => 10]],
            $this->sessionBasketLines(),
        );
    }

    public function test_a_stranger_cannot_load_someone_elses_basket(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $milk = Product::factory()->create(['slug' => 'mleko']);

        $basket = $owner->savedBaskets()->create(['name' => 'Zakupy']);
        $basket->items()->create(['product_id' => $milk->id, 'quantity' => 2]);

        $response = $this->actingAs($stranger)->post(route('saved.load', $basket->id));

        $response->assertNotFound();
        $this->assertSame([], $this->sessionBasketLines());
    }

    /**
     * @return list<array{product: string, quantity: int}>
     */
    private function sessionBasketLines(): array
    {
        return (new BasketSession(session()->driver()))->lines();
    }
}
