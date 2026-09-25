<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BusinessReadyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $business = $request->user()?->business;

        //if business is missing or not ready, redirect to onboarding
        if (! $business?->admin_setup_complete) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
