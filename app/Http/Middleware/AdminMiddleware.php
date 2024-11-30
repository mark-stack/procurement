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

        // Redirect or abort if the condition fails
        return redirect('/')->with('error', 'Unauthorized access.');
    }
}
