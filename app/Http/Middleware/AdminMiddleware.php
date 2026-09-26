<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if the authenticated user's email matches the condition
        if (auth()->check() && auth()->user()->isAdmin()) {
            return $next($request);
        }

        /*
         * A signed-out admin used to be sent to '/' with no way back to the page they were
         * on. redirect()->guest() stores the url so logging in returns them to it.
         */
        if (! auth()->check()) {
            return redirect()->guest(route('login'));
        }

        /*
         * 'warning', not 'error': HandleInertiaRequests shares success, warning, project
         * and materialsImport, so the old 'error' key was flashed to a page that had no way
         * to read it and a non-admin was bounced to '/' with no explanation at all.
         */
        return redirect('/')->with('warning', 'Unauthorized access.');
    }
}
