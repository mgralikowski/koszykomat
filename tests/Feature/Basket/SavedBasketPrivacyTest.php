<?php

namespace Tests\Feature\Basket;

use App\Models\Product;
use App\Models\SavedBasket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one guarantee this slice cannot get wrong: a saved basket belongs to exactly one account
 * and is invisible to every other (PRD NFR — "zapisane koszyki są widoczne wyłącznie dla
 * właściciela konta").
 *
 * The oracle is that NFR, not the controller: the expected status is 404 rather than 403 because
 * a 403 answers "yes, that basket exists" to a stranger, which is itself the leak. A regression
 * that swapped the owner-scoped lookup for an implicit route-model binding would still deny the
 * request — with a 403 — and a test that accepted "any denial" would pass right through it.
 */
class SavedBasketPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_google_rather_than_the_saved_baskets(): void
    {
        // Auth is OAuth-only, so there is no `login` route to fall back on — a misconfigured gate
        // fails here as a RouteNotFoundException (a 500), not as a redirect.
        $response = $this->get(route('saved.index'));

        $response->assertRedirect(route('auth.google.redirect'));
    }

    public function test_the_list_shows_only_the_signed_in_users_baskets(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $owner->savedBaskets()->create(['name' => 'Koszyk właściciela']);
        $stranger->savedBaskets()->create(['name' => 'Koszyk obcego']);

        $response = $this->actingAs($stranger)->get(route('saved.index'));

        $response->assertOk();
        $response->assertSee('Koszyk obcego');
        $response->assertDontSee('Koszyk właściciela');
    }

    public function test_a_stranger_deleting_someone_elses_basket_gets_404_and_the_basket_survives(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $basket = $owner->savedBaskets()->create(['name' => 'Koszyk właściciela']);

        $response = $this->actingAs($stranger)->delete(route('saved.destroy', $basket->id));

        // Not 403: that would confirm the basket exists.
        $response->assertNotFound();
        $this->assertTrue(SavedBasket::whereKey($basket->id)->exists());
    }

    public function test_the_owner_can_delete_their_own_basket(): void
    {
        $owner = User::factory()->create();
        $basket = $owner->savedBaskets()->create(['name' => 'Koszyk właściciela']);
        $basket->items()->create([
            'product_id' => Product::factory()->create()->id,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($owner)->delete(route('saved.destroy', $basket->id));

        $response->assertRedirect(route('saved.index'));
        $this->assertFalse(SavedBasket::whereKey($basket->id)->exists());
        $this->assertDatabaseCount('saved_basket_items', 0);
    }

    public function test_saving_stores_the_basket_against_the_signed_in_user_only(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $product = Product::factory()->create();

        $response = $this->actingAs($owner)
            ->withSession(['basket.lines' => [$product->slug => 2]])
            // A forged user_id must not decide the owner: ownership comes from the relation the
            // request was scoped to, never from the request body.
            ->post(route('saved.store'), ['name' => 'Mój koszyk', 'user_id' => $stranger->id]);

        $response->assertRedirect(route('saved.index'));
        $this->assertSame(1, $owner->savedBaskets()->count());
        $this->assertSame(0, $stranger->savedBaskets()->count());
    }
}
