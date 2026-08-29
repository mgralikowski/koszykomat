<?php

namespace Tests\Feature;

use App\Models\Leaflet;
use Database\Seeders\ExampleBasketSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the wiring: the numbers on the page are the numbers the engine computed.
 *
 * The mandatory promo tests deliberately stop at the engine, so this is the only place that
 * catches a view rendering the wrong scenario, dropping a mechanic label, or — worst of all —
 * naming a winner on data the engine refused to price.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_verdict_and_both_chain_totals(): void
    {
        $this->seed(ExampleBasketSeeder::class);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Lidl');
        $response->assertSee('Biedronka');

        // The engine's figures for the seeded basket, straight off the page.
        $response->assertSee('62,43 zł');
        $response->assertSee('69,46 zł');
        $response->assertSee('67,46 zł');
        $response->assertSee('Taniej w');
    }

    public function test_it_shows_both_card_scenarios_when_the_card_changes_the_outcome(): void
    {
        $this->seed(ExampleBasketSeeder::class);

        $response = $this->get('/');

        $response->assertSee('bez karty');
        $response->assertSee('z kartą lojalnościową');
    }

    public function test_it_shows_the_matched_pair_with_brand_and_size(): void
    {
        $this->seed(ExampleBasketSeeder::class);

        $response = $this->get('/');

        // FR-008: the user must see what was compared with what, including a size difference.
        $response->assertSee('Kawa ziarnista Bellarom');
        $response->assertSee('Kawa ziarnista Lavazza');
        $response->assertSee('Bellarom');
        $response->assertSee('Lavazza');
        $response->assertSee('1 kg');
        $response->assertSee('900 g');
    }

    public function test_it_shows_the_applied_mechanic_and_the_validity_window(): void
    {
        $this->seed(ExampleBasketSeeder::class);

        $response = $this->get('/');

        $response->assertSee('1+1 gratis');
        $response->assertSee('Gazetka ważna');
        $response->assertSee(today()->startOfWeek()->format('d.m.Y'));
    }

    public function test_the_page_survives_an_empty_database(): void
    {
        // No seeder at all: no chains, no products, no leaflets. The public landing page must
        // still render — a deploy that runs before the first ingestion is a normal state, not a
        // 500 — and it must refuse to name a winner.
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Brak danych');
        $response->assertDontSee('Taniej w');
    }

    public function test_expired_data_shows_no_data_and_names_no_winner(): void
    {
        $this->seed(ExampleBasketSeeder::class);

        Leaflet::query()->update([
            'valid_from' => today()->subDays(30),
            'valid_to' => today()->subDays(20),
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Brak danych');
        $response->assertDontSee('Taniej w');
    }
}
