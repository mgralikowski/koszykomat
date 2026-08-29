<?php

namespace Tests\Feature\Database;

use App\Models\Leaflet;
use App\Models\PriceEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the freshness chokepoint.
 *
 * Every comparison reads prices through these scopes, so a boundary bug here does not produce an
 * error — it produces a false "brak danych" on a day the data is perfectly valid. The datetime
 * string case is the one that regressed: the signature accepts strings, and a caller passing
 * now()->toDateTimeString() used to lose the leaflet's own last valid day.
 */
class LeafletValidityScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_last_valid_day_is_still_valid_for_a_datetime_string(): void
    {
        $leaflet = Leaflet::factory()->create([
            'valid_from' => today()->subDays(3),
            'valid_to' => today()->addDays(3),
        ]);
        PriceEntry::factory()->create(['leaflet_id' => $leaflet->id]);

        $lastDay = $leaflet->valid_to->toDateString();

        $this->assertSame(1, Leaflet::validOn($lastDay.' 12:00:00')->count());
        $this->assertSame(1, PriceEntry::validOn($lastDay.' 12:00:00')->count());
    }

    public function test_the_first_valid_day_is_valid_for_a_datetime_string(): void
    {
        $leaflet = Leaflet::factory()->create([
            'valid_from' => today()->subDays(3),
            'valid_to' => today()->addDays(3),
        ]);

        $this->assertSame(1, Leaflet::validOn($leaflet->valid_from->toDateString().' 23:30:00')->count());
    }

    public function test_it_excludes_the_days_just_outside_the_window(): void
    {
        $leaflet = Leaflet::factory()->create([
            'valid_from' => today()->subDays(3),
            'valid_to' => today()->addDays(3),
        ]);

        $this->assertSame(0, Leaflet::validOn($leaflet->valid_from->copy()->subDay())->count());
        $this->assertSame(0, Leaflet::validOn($leaflet->valid_to->copy()->addDay())->count());
    }

    public function test_carbon_and_string_arguments_agree(): void
    {
        $leaflet = Leaflet::factory()->create([
            'valid_from' => today()->subDays(3),
            'valid_to' => today()->addDays(3),
        ]);

        $day = $leaflet->valid_to->toDateString();

        $this->assertSame(
            Leaflet::validOn($leaflet->valid_to)->count(),
            Leaflet::validOn($day)->count(),
        );
        $this->assertSame(
            Leaflet::validOn($day)->count(),
            Leaflet::validOn($day.' 18:45:00')->count(),
        );
    }
}
