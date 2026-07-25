<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The canonical, chain-neutral comparison unit (e.g. "mleko 3,2% 1 l").
     *
     * This is what basket items point at, and what joins the two chains' listings together —
     * the cross-network pairing required by FR-008 is this foreign key, not a matching table.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
