<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One external OAuth identity mapped to one local user.
     *
     * A user is identified by (provider, provider_user_id), never by email — email changes at
     * the provider must not orphan an account. Kept as its own table rather than columns on
     * `users` so a second provider needs no schema change once real accounts exist.
     *
     * `provider` is a plain string column cast to App\Enums\OauthProvider in the model, the same
     * arrangement `price_entries.promo_type` uses: enum DDL is MySQL-specific and the test suite
     * runs on SQLite, so the closed set lives in PHP rather than in the schema.
     */
    public function up(): void
    {
        Schema::create('oauth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_id');
            $table->timestamps();

            // The login lookup key, and the guard against two users claiming one Google account.
            $table->unique(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_identities');
    }
};
