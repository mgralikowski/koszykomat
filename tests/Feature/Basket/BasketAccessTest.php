<?php

namespace Tests\Feature\Basket;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one security-shaped guarantee in this slice: the basket belongs to a session.
 *
 * Auth is OAuth-only, so there is no `login` route for the framework to fall back on — a
 * misconfigured gate here fails as a RouteNotFoundException (a 500), not as a redirect, which
 * is exactly the failure a smoke test would miss and this one catches.
 */
class BasketAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_google_rather_than_the_basket(): void
    {
        $response = $this->get(route('basket.index'));

        $response->assertRedirect(route('auth.google.redirect'));
    }

    public function test_an_authenticated_user_reaches_the_basket(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('basket.index'));

        $response->assertOk();
        $response->assertSee('Twój koszyk');
    }
}
