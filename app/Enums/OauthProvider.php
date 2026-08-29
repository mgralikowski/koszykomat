<?php

namespace App\Enums;

/**
 * The OAuth providers users can authenticate with (PRD FR-002).
 *
 * Google is the only case in the MVP; more chains of identity are a v2 concern, and the
 * `oauth_identities` schema is shaped to take them without a migration. The backing value is
 * also the Socialite driver name, so `Socialite::driver($provider->value)` resolves the
 * matching `services.<value>` config block.
 */
enum OauthProvider: string
{
    case Google = 'google';
}
