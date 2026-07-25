<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The dated container every price belongs to.
     *
     * Gives each price its from–to validity window (data-freshness NFR: the report always shows
     * how current a price is) and gives the future ingestion pipeline an atomic unit of work —
     * one leaflet row plus its entries, replaceable together.
     */
    public function up(): void
    {
        Schema::create('leaflets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->date('valid_from');
            $table->date('valid_to');
            // Hook for the graphic-format provider without committing to its shape yet.
            $table->string('source_type')->default('manual');
            $table->string('source_reference')->nullable();
            $table->timestamps();

            // Keeps the "current leaflet for this chain" lookup cheap.
            $table->index(['network_id', 'valid_from', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaflets');
    }
};
