<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Resolves the signed-in user for routes behind the auth middleware.
 *
 * Throwing instead of returning null keeps controllers free of null checks
 * and lets static analysis (PHPStan level 8) prove the user is present.
 */
final class AuthenticatedUser
{
    public static function from(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
