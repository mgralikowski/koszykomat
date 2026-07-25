<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One chain's concrete listing of a canonical product.
     *
     * Carries the attributes FR-008 must surface — the chain's own product name, the brand,
     * and the size label — so the report can show what was paired with what and the user can
     * judge comparability. `size_label` is a display string, not a normalized quantity: weight
     * normalization is an explicit PRD non-goal.
     */
    public function up(): void
    {
        Schema::create('network_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('size_label')->nullable();
            $table->timestamps();

            // At most one listing per chain per canonical product — what a single nationwide
            // leaflet price implies. Product variants would need this revisited.
            $table->unique(['network_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_products');
    }
};
