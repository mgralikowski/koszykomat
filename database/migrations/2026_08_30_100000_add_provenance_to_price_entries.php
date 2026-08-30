<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-row provenance for ingested prices.
 *
 * F-01 modelled provenance at leaflet level (`leaflets.source_type`), which was adequate while
 * every row was hand-seeded. Ingestion breaks that assumption: a Lidl row comes from an exact PDF
 * text layer, a Biedronka row is a vision model's reading, and both can sit in the same leaflet.
 * Trust therefore has to be recorded per row, not per source.
 *
 * `needs_review` is the load-bearing column: it is what the validation gate sets and what the
 * verdict excludes, so a price nobody verified can never be presented as fact. It defaults to
 * false so every existing seeded row stays trusted and no backfill is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_entries', function (Blueprint $table) {
            // Which driver produced the row, for audit — e.g. 'lidl.pdf_text'.
            $table->string('source')->nullable()->after('second_item_price');

            // 1.00 for deterministic extraction; model-derived below that. Nullable because
            // hand-seeded rows predate the concept and must not claim a confidence they never had.
            $table->decimal('confidence', 3, 2)->nullable()->after('source');

            // Set by App\Ingestion\Validation\PriceEntryGate. Excluded from every verdict.
            $table->boolean('needs_review')->default(false)->after('confidence');

            // The vision model's box_2d crop reference, so a flagged row resolves to a picture.
            $table->json('source_box')->nullable()->after('needs_review');
        });
    }

    public function down(): void
    {
        Schema::table('price_entries', function (Blueprint $table) {
            $table->dropColumn(['source', 'confidence', 'needs_review', 'source_box']);
        });
    }
};
