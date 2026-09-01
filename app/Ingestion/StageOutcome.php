<?php

namespace App\Ingestion;

/**
 * What one pipeline stage's driver list produced: the first usable result, and the reason every
 * driver that threw on the way there gave.
 *
 * The driver list is a fallback chain — a driver that blows up hands over to the next one, which is
 * the entire reason a chain may list more than one. `failures` is therefore filled in only when
 * *nothing* survived: a crash a later driver covered for is a working fallback, not an incident,
 * and it stays in the log where it belongs. What remains is the case that keeps that tolerance from
 * decaying into silence — a stage where every driver crashed, which is otherwise indistinguishable
 * from a chain that simply published no leaflet this week. Both used to read the same way to the
 * nightly cron: nothing happened, exit 0.
 */
final readonly class StageOutcome
{
    /**
     * @param  list<mixed>  $items
     * @param  list<string>  $failures  one line per driver that threw, when none of them recovered
     */
    public function __construct(public array $items = [], public array $failures = []) {}
}
