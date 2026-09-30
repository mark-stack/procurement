<?php

/*
 * Overrides for cesargb/laravel-magiclink. Only the keys below are set here - the package's service
 * provider mergeConfigFrom()s its own file underneath this one, so everything not named here keeps
 * the package default.
 *
 * A magic link in this application is a LoginAction: visiting the url authenticates the recipient,
 * with no password and no second factor. Six notifications mint one (the four reminders, the
 * activation welcome, and the trial warning), so these numbers decide how long an emailed full login
 * stays usable.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | Attempts per minute against the token-validation route. The package default is 'none', so
    | the 64-character token was guessable at whatever rate a client could manage - not a
    | realistic attack on that length, but there is no reason to leave the door swinging.
    |
    */

    'rate_limit' => env('MAGICLINK_RATE_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Emailed logins
    |--------------------------------------------------------------------------
    |
    | Read by App\Notifications\Concerns\SignsInByLink, which is how every one of them is made.
    |
    | MagicLink::create() defaults to 4320 minutes - three days - and to an unlimited number of
    | visits, and all six call sites took both defaults. Procurement mail is forwarded as a matter
    | of routine: a "quote due" reminder goes on to the merchant, and with it went a working login
    | to the fabricator's account, good for three days and for as many people as the thread
    | reached.
    |
    | Twelve hours, so a reminder read after knocking-off still works and one read tomorrow does
    | not. Three visits rather than one: a single-use token is the stronger answer, but mail
    | scanners and link-rewriting gateways follow urls before a human sees them, and burning the
    | login on the way through the recipient's own mail filter fails the wrong way. Three is enough
    | for a scanner and a click, and far short of a forwarded thread.
    |
    | Signing in with a password is always available, so nothing is lost when one of these expires.
    |
    */

    'login' => [
        'lifetime_minutes' => (int) env('MAGICLINK_LOGIN_LIFETIME_MINUTES', 720),
        'max_visits' => (int) env('MAGICLINK_LOGIN_MAX_VISITS', 3),
    ],

];
