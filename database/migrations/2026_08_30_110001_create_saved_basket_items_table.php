<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line of a saved basket: which canonical product, how many.
     *
     * Products and quantities only — never prices. FR-005 asks for re-comparison after the data
     * refreshes, and BasketComparator resolves every price through PriceEntry::usableOn() at call
     * time, so a stored price would not make the answer faster, only wrong.
     *
     * `product_id` cascades on delete: a product that leaves the catalogue leaves every saved
     * basket that referenced it. The alternative — restricting the delete — would let user data
     * block catalogue maintenance, and a dangling reference would force the report to render a
     * line for a product that no longer exists.
     *
     * `quantity` is stored inside the config bounds it was clamped to on save, but it is clamped
     * again on load: max_quantity can be lowered afterwards, and PromoCalculator multiplies
     * through Money::times($quantity) with no limit of its own.
     */
    public function up(): void
    {
        Schema::create('saved_basket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_basket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->timestamps();

            // The session basket merges duplicate lines by construction (BasketSession keys its
            // map by slug), so two rows for one product in one saved basket is a shape the
            // application cannot produce and the database should not accept.
            $table->unique(['saved_basket_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_basket_items');
    }
};
