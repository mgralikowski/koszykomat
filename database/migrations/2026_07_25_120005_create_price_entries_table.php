<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One priced offer: one chain's product listing, inside one leaflet, under one promo mechanic.
     *
     * `leaflet_id` is deliberately NOT nullable — every price, including a plain shelf reference
     * price, inherits a validity window. That makes the data-freshness NFR structural and reduces
     * the "no data instead of a stale verdict" guardrail to one uniform question: does this
     * listing have a non-expired entry?
     *
     * `promo_type` is a plain string column, not `$table->enum()`: enum DDL is MySQL-specific,
     * awkward to alter, and diverges from how the in-memory SQLite test connection emulates it.
     * The model casts it to App\Enums\PromoType.
     *
     * The promo parameter matrix — which of `promo_price` / `required_quantity` /
     * `second_item_price` is meaningful for which mechanic — is enforced in application code
     * (PromoType) and asserted by tests, because the check constraints needed to express it are
     * not portable between MySQL 8 and SQLite.
     */
    public function up(): void
    {
        Schema::create('price_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leaflet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_product_id')->constrained()->cascadeOnDelete();
            // Always present: the undiscounted unit price, so both the no-promo baseline and the
            // cost of a forced overbuy stay computable.
            $table->decimal('regular_price', 8, 2);
            $table->string('promo_type')->default('none'); // App\Enums\PromoType::None
            $table->decimal('promo_price', 8, 2)->nullable();
            $table->unsignedTinyInteger('required_quantity')->nullable();
            $table->decimal('second_item_price', 8, 2)->nullable();
            $table->timestamps();

            // Lets one listing carry both a regular price and a loyalty-card price in the same
            // leaflet (the FR-007 case where the card splits the verdict), while still blocking
            // duplicate rows of the same mechanic. Also covers leaflet_id lookups.
            $table->unique(['leaflet_id', 'network_product_id', 'promo_type']);

            // No explicit index on network_product_id: InnoDB creates one for the foreign key
            // constraint above, so declaring it again would just duplicate that index.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_entries');
    }
};
