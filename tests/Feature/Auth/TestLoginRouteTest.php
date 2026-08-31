<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The /_test/login door is an authentication bypass. It exists only so browser tests can reach
 * the logged-in half of the product, because the real way in is Google OAuth (FR-002) and
 * Playwright cannot drive Google.
 *
 * These cases assert the guard, not the door. The suite runs with APP_ENV=testing, which is not
 * `local`, so a correctly guarded route is simply absent here. If any of these ever goes red,
 * the bypass has escaped the local environment and every account is reachable without
 * credentials — treat it as a production incident, not a failing test.
 */
class TestLoginRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_suite_does_not_run_as_local(): void
    {
        // The precondition the other two cases rest on. Without it they would pass vacuously
        // against a route that is, in fact, always registered.
        $this->assertFalse(app()->environment('local'));
    }

    public function test_the_route_is_not_registered_outside_local(): void
    {
        $this->assertFalse(Route::has('test.login'));
    }

    public function test_requesting_it_outside_local_yields_no_session_and_no_account(): void
    {
        $this->get('/_test/login')->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'e2e@koszykomat.test']);
    }
}
