<?php

namespace App\Http\Controllers;

use App\Billing\Billing;
use App\Billing\Plans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Send someone off to pay.
 *
 * POST, because it creates a checkout session at the provider - a link, a prefetch or a crawler
 * must not be able to.
 */
class BillingCheckoutController extends Controller
{
    public function __construct(
        private readonly Billing $billing,
        private readonly Plans $plans,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $business = $request->user()?->business;

        if ($business === null) {
            return redirect()->route('onboarding');
        }

        $validated = $request->validate([
            'plan' => ['required', 'string'],
        ]);

        //404 on an unknown key rather than a 500 further down
        $plan = $this->plans->findOrFail($validated['plan']);

        if (! $this->billing->provider()->canSell($plan)) {
            return redirect()
                ->route('billing.index')
                ->with('warning', 'That plan cannot be bought online yet. Ask for an invoice and it will be set up by hand.');
        }

        try {
            $url = $this->billing->provider()->checkoutUrl(
                $business,
                $plan,
                route('billing.index', ['checkout' => 'success']),
                route('billing.index', ['checkout' => 'cancelled']),
            );
        } catch (Throwable $e) {
            /*
             * The provider is a third party over the network: a bad price id, an expired API key or
             * an outage all land here. None of them are worth a 500 on a page whose whole job is to
             * take the customer's money - say so and leave the invoice route open.
             */
            Log::error('Billing checkout could not be started', [
                'business_id' => $business->id,
                'plan' => $plan->key,
                'provider' => $this->billing->provider()->name(),
                'exception' => $e->getMessage(),
            ]);

            return redirect()
                ->route('billing.index')
                ->with('warning', 'The payment page could not be opened just now. Try again shortly, or ask for an invoice instead.');
        }

        /*
         * Inertia::location rather than a redirect: an Inertia POST follows a 302 by XHR, and the
         * provider's checkout is another origin, so the browser has to be told to leave the app.
         */
        return Inertia::location($url);
    }
}
