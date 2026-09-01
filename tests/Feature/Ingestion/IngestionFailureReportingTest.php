<?php

namespace Tests\Feature\Ingestion;

use App\Ingestion\Contracts\Discoverer;
use App\Ingestion\Flyer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The nightly refresh has to report its own failures (FR-009; infrastructure.md risk register,
 * "dead refresh cron serving stale prices").
 *
 * The production cron entry (deploy/SERVER-SETUP.md §7) ends in `>> /dev/null 2>&1`, so nothing the
 * command prints reaches a human. Its exit code is the only channel left — it is what the
 * scheduler's `emailOutputOnFailure` and any exit-code monitor read. So the exit code is what these
 * tests assert on, not the console text.
 *
 * The pair of boundaries matters as much as the alarm itself. Exit non-zero when the run could not
 * do its job and will not recover on its own; exit zero when it merely had less to do than hoped. An
 * alarm that fires on a healthy fallback gets muted, and a muted alarm is the bug we started from.
 */
class IngestionFailureReportingTest extends TestCase
{
    use RefreshDatabase;

    private function chain(array $discoverers): void
    {
        config()->set('leaflets.chains', [
            'lidl' => ['discoverers' => $discoverers, 'acquirers' => [], 'parsers' => []],
        ]);
    }

    public function test_it_fails_the_command_when_every_discovery_driver_throws(): void
    {
        $this->chain([ExplodingDiscoverer::class]);

        $this->artisan('leaflets:ingest', ['network' => 'lidl'])
            ->expectsOutputToContain('Lidl changed its HTML')
            ->assertFailed();
    }

    public function test_it_fails_the_command_when_the_chain_slug_is_not_configured(): void
    {
        // A typo in the cron entry ingests nothing every night, forever, and looked identical to a
        // healthy run before this.
        $this->chain([WorkingDiscoverer::class]);

        $this->artisan('leaflets:ingest', ['network' => 'lidI'])
            ->expectsOutputToContain("no chain configured as 'lidI'")
            ->assertFailed();
    }

    public function test_it_succeeds_when_a_fallback_driver_covers_for_one_that_threw(): void
    {
        // Listing more than one driver is exactly so a crash can be survived. Alarming here would
        // punish the design that makes the pipeline resilient.
        $this->chain([ExplodingDiscoverer::class, WorkingDiscoverer::class]);

        $this->artisan('leaflets:ingest', ['network' => 'lidl'])->assertSuccessful();
    }

    public function test_it_succeeds_when_the_chain_simply_published_no_leaflet(): void
    {
        // No driver failed; there was nothing to fetch. A quiet week is not an incident.
        $this->chain([SilentDiscoverer::class]);

        $this->artisan('leaflets:ingest', ['network' => 'lidl'])
            ->expectsOutputToContain('discovery returned no leaflet')
            ->assertSuccessful();
    }
}

/**
 * A driver whose upstream shape changed under it — the way discovery actually dies.
 */
class ExplodingDiscoverer implements Discoverer
{
    public function discover(): array
    {
        throw new \RuntimeException('Lidl changed its HTML — selector matched nothing');
    }
}

class WorkingDiscoverer implements Discoverer
{
    public function discover(): array
    {
        return [new Flyer('lidl', 'flyer-1', 'Gazetka', Carbon::today(), Carbon::today()->addDays(6))];
    }
}

/**
 * Reaches the chain, is told there is no current leaflet, and says so without incident.
 */
class SilentDiscoverer implements Discoverer
{
    public function discover(): array
    {
        return [];
    }
}
