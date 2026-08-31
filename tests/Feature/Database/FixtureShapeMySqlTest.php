<?php

namespace Tests\Feature\Database;

use App\Models\PriceEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * A fixture must be a row production would accept — and SQLite cannot see that.
 *
 * The suite runs on SQLite `:memory:` while production is MySQL 8.0. Laravel's SQLite grammar emits
 * a bare `numeric` column, dropping precision and scale entirely, and SQLite ignores integer width,
 * so it stores values MySQL rejects outright under strict mode. A fixture carrying such a value
 * passes green locally and throws in production: the Risk #6 failure in its purest form.
 *
 * Run with `composer test:mysql` (see `composer test:mysql:setup` for the one-off schema creation).
 * This class is tagged `mysql` and excluded from every SQLite lane, because on SQLite its
 * acceptance cases would pass vacuously and its rejection cases would fail — the worst of both.
 */
#[Group('mysql')]
class FixtureShapeMySqlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fail loudly rather than pass vacuously if this class is ever run on SQLite. A green run
     * against the wrong driver would prove precisely nothing, which is the trap the class exists
     * to close.
     */
    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (QueryException $exception) {
            // Laravel creates a missing database on its own, so the step that actually needs doing
            // once per environment is the GRANT. Without it the raw driver error names the user and
            // the schema but not the remedy, which costs a contributor a search.
            $this->fail(
                'Cannot reach the MySQL test schema. Run `composer test:mysql:setup` once per environment '
                ."to create it and grant access.\nDriver said: ".$exception->getMessage()
            );
        }

        $driver = DB::connection()->getDriverName();

        $this->assertSame(
            'mysql',
            $driver,
            "This class asserts MySQL 8.0 column constraints but is running on [{$driver}]. Run it with `composer test:mysql`."
        );
    }

    /**
     * Every state the factory can produce must survive a real production INSERT.
     *
     * @return array<string, array{string, array<int, mixed>}>
     */
    public static function factoryStates(): array
    {
        return [
            'default (no promo)' => ['', []],
            'simple' => ['simple', []],
            'one_plus_one at the seeded threshold' => ['onePlusOne', []],
            'one_plus_one at a real leaflet threshold' => ['onePlusOne', [3]],
            'second_for_fixed at the seeded threshold' => ['secondForFixed', []],
            'second_for_fixed at a real leaflet threshold' => ['secondForFixed', ['0.01', 3]],
            'loyalty_card' => ['loyaltyCard', []],
            'conditional_unit_price' => ['conditionalUnitPrice', []],
            'conditional_unit_price at a real leaflet threshold' => ['conditionalUnitPrice', ['1.99', 6]],
        ];
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    #[DataProvider('factoryStates')]
    public function test_a_factory_row_is_a_shape_production_accepts(string $state, array $arguments): void
    {
        $factory = PriceEntry::factory();

        if ($state !== '') {
            $factory = $factory->{$state}(...$arguments);
        }

        $entry = $factory->create();

        $this->assertTrue($entry->exists, 'MySQL 8.0 must accept every row the factory produces.');
        $this->assertSame($entry->leaflet->network_id, $entry->networkProduct->network_id);
    }

    /**
     * The other half, and the half that makes a green run mean something: values production would
     * reject must actually be rejected here. Both are reachable from the ingestion path, which
     * writes raw parser output into these columns for flagged rows.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function rowsProductionRejects(): array
    {
        return [
            // `required_quantity` is an unsignedTinyInteger; a misread "Limit: 300 opak." overflows it.
            'threshold beyond the tiny integer range' => [
                ['required_quantity' => 300],
                'required_quantity',
            ],
            // `regular_price` is decimal(8,2); a misplaced decimal point overflows it.
            'price beyond the decimal(8,2) range' => [
                ['regular_price' => '1000000.00'],
                'regular_price',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('rowsProductionRejects')]
    public function test_a_row_production_cannot_hold_is_rejected(array $attributes, string $column): void
    {
        $this->expectException(QueryException::class);

        PriceEntry::factory()->create($attributes);

        $this->fail("MySQL 8.0 accepted an out-of-range [{$column}]; the column no longer constrains what a fixture may carry.");
    }
}
