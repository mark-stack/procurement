<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only, rather than locked out, when a trial or subscription has lapsed.
 *
 * A fabricator halfway through a job has cutting lists in here that the saw is working from. Taking
 * those away over a lapsed card would be the wrong kind of leverage, and it would also destroy the
 * only reason they have to come back and pay. So everything stays readable and printable: projects,
 * batches, nesting plans, the BOM and nesting downloads.
 *
 * What stops is changing anything. Deciding that by HTTP verb rather than by a list of route names
 * is the point - it cannot fall out of date as routes are added, and it lines up exactly with the
 * GET/POST split this application already keeps to (the admin routes carry comments explaining why
 * each state change is a POST). The one cost is that a future write hidden behind a GET would slip
 * through, which is why there is a test asserting the shape of the rule rather than one example.
 */
class BillingWriteAccessMiddleware
{
    /**
     * Verbs that change something. HEAD and OPTIONS are omitted with GET for the same reason.
     */
    private const array READ_ONLY_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::READ_ONLY_METHODS, true)) {
            return $next($request);
        }

        $business = $request->user()?->business;

        /*
         * A user with no business at all is someone else's problem - BusinessReadyMiddleware sends
         * them to onboarding. Refusing here would turn that redirect into a dead end.
         */
        if ($business === null || $business->allowsWrites()) {
            return $next($request);
        }

        $state = $business->billingState();

        return redirect()
            ->route('billing.index')
            ->with('warning', $state->everPaid()
                ? 'Your subscription has ended, so the account is read-only. Everything you have is still here to read and print - subscribe again to make changes.'
                : 'Your free trial has ended, so the account is read-only. Everything you have is still here to read and print - subscribe to make changes.');
    }
}
