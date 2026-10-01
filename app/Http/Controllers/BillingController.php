<?php

namespace App\Http\Controllers;

use App\Billing\Billing;
use App\Billing\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function __construct(private readonly Billing $billing) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $business = $request->user()?->business;

        //Nothing to bill without a business, and no page in here can fix that
        if ($business === null) {
            return redirect('/');
        }

        return Inertia::render('Billing/Index', [
            'billing' => $business->billingState()->toArray(),

            'plans' => array_map(
                fn (Plan $plan): array => $plan->toArray(),
                $this->billing->sellablePlans(),
            ),

            /*
             * Whether subscribing leads to a card form at the provider or to an invoice request in
             * this application, which is the difference between what the button can honestly say.
             * False on an installation with no provider configured - the arrangement this product
             * was sold under before any of it was automated.
             */
            'hostedCheckout' => $this->billing->provider()->hostedCheckout(),

            'invoiceEmail' => config('billing.invoice_email'),
            'trialDays' => (int) config('billing.trial_days'),

            /*
             * Stripe sends the customer back here the moment they pay, but the subscription itself
             * arrives by webhook a second or two later, so the state above can still read as lapsed
             * on this very page load. The page says "activating" rather than contradicting itself.
             */
            'checkout' => $request->query('checkout'),
        ]);
    }
}
