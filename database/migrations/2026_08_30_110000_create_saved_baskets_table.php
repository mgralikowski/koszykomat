<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One named basket kept on an account (FR-005), so a basket survives the session it was
     * built in.
     *
     * Deleting the account takes its saved baskets with it: there is no other owner they could
     * belong to, and the privacy NFR means an ownerless basket is a row nothing may ever read.
     *
     * Deliberately NOT unique on (user_id, name). Saving always inserts a new basket — there is
     * no update-in-place — so a uniqueness rule would reject the save-edit-save-again loop that
     * is the normal way to iterate on a basket. The list disambiguates by save date instead.
     *
     * `user_id` carries no explicit index: constrained() creates the foreign key, and both MySQL
     * and SQLite index a foreign key column, which is what the owner-scoped list query reads.
     */
    public function up(): void
    {
        Schema::create('saved_baskets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_baskets');
    }
};
