<?php

namespace App\Services;

use App\Enums\RoleEnum;
use App\Models\User;

/**
 * Never-expiring master API token used by external integrations (Pearl XP).
 *
 * The token is a regular Sanctum personal access token whose `name` matches the
 * sentinel below. AppServiceProvider hooks Sanctum::authenticateAccessTokensUsing()
 * so that tokens with this name ignore config('sanctum.expiration') entirely —
 * they stay valid until `php artisan master-token:reset` revokes them.
 */
class MasterToken
{
    /**
     * Sentinel token name. Also acts as the marker for the expiry exemption.
     */
    public const NAME = 'pearl-xp-master-token';

    /**
     * Determine whether the given access token is the master token.
     *
     * @param  mixed  $accessToken
     */
    public static function isMasterToken($accessToken): bool
    {
        return ! is_null($accessToken)
            && ($accessToken->name ?? null) === self::NAME;
    }

    /**
     * Revoke any previous master token for the user and issue a fresh one.
     *
     * Only the SHA-256 hash is stored by Sanctum, so the returned plain text
     * token must be shown to the operator exactly once.
     */
    public static function mint(User $user): string
    {
        // Revoke the old master token first so only the newest one works.
        $user->tokens()->where('name', self::NAME)->delete();

        $plainTextToken = $user->createToken(self::NAME)->plainTextToken;

        // Helpers::getCurrentRoleName() reads tokens.first().role_type, so keep
        // every token for this user in sync — the master token always
        // authenticates as an admin (mirrors AuthController@login).
        $user->tokens()->update(['role_type' => RoleEnum::ADMIN]);

        return $plainTextToken;
    }
}
