<?php

require __DIR__.'/auth.php';
require __DIR__.'/authRoutes.php';
require __DIR__.'/guestRoutes.php';
require __DIR__.'/adminRoutes.php';

/*
 * Scratch routes for working on the algorithms by hand. They are unauthenticated and dump internal
 * data straight to the response, so they must never be registered outside local development.
 */
if (app()->environment('local')) {
    require __DIR__.'/experimentalRoutes.php';
}
