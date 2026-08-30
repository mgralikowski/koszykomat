<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An account. There is no password: users authenticate only through a linked OAuth identity
 * (PRD FR-002), and the session guard never asks for one.
 */
#[Fillable(['name', 'email'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return HasMany<OauthIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(OauthIdentity::class);
    }

    /**
     * The baskets this account has kept (FR-005).
     *
     * This relation is also the scoping root for every saved-basket lookup: resolving through
     * $user->savedBaskets() means another account's id is simply not in the result set, so it
     * 404s rather than being fetched and then checked. The privacy NFR is the query, not a guard
     * a later caller could forget.
     *
     * @return HasMany<SavedBasket, $this>
     */
    public function savedBaskets(): HasMany
    {
        return $this->hasMany(SavedBasket::class);
    }

    /**
     * There is no password to return. This exists only so the framework's password-shaped call
     * sites receive a string: SessionGuard passes it to hash_hmac() when issuing a remember-me
     * cookie, and null triggers a PHP 8.5 deprecation there.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }
}
