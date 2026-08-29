<?php

namespace App\Models;

use Database\Factories\OauthIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One external OAuth identity (provider + the provider's own user id) linked to a local user.
 *
 * This pair, not the email address, is what identifies a returning user at login.
 */
#[Fillable(['user_id', 'provider', 'provider_user_id'])]
class OauthIdentity extends Model
{
    /** @use HasFactory<OauthIdentityFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
