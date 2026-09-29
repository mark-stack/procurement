<?php

namespace App\Sandbox;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Which set of data the current request is looking at: somebody's sandbox, or the live one.
 *
 * One answer, asked for in three places - the global scope that filters projects and batches, the
 * stamp put on new ones, and the banner. If they could disagree, a project created in test mode
 * would be filtered out of the very board it was created from.
 *
 * Read off the authenticated user, so anything running without one - the hourly notification
 * checks, the queue, artisan, a seeder - sees live data and only live data. That is deliberate:
 * nobody should be chased by email about a project they made up to see what the button did.
 */
class Sandbox
{
    /**
     * The user whose test data is in scope, or null when the live data is.
     */
    public static function ownerId(): ?int
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->sandbox_mode ? $user->id : null;
    }

    public static function isActive(): bool
    {
        return self::ownerId() !== null;
    }
}
