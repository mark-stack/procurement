<?php

namespace App\Http\Controllers;

use App\Billing\Plans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ask for an invoice instead of paying by card.
 *
 * Plenty of fabricators will not put a company card into a web form, and this is how the product was
 * sold before any provider was wired in. Reachable whichever driver is live, and the only checkout
 * the manual driver has.
 */
class BillingInvoiceController extends Controller
{
    public function __construct(private readonly Plans $plans) {}

    public function __invoke(Request $request, string $plan): Response|RedirectResponse
    {
        $business = $request->user()?->business;

        if ($business === null) {
            return redirect()->route('onboarding');
        }

        return Inertia::render('Billing/RequestInvoice', [
            'plan' => $this->plans->findOrFail($plan)->toArray(),
            'invoiceEmail' => config('billing.invoice_email'),
            'businessName' => $business->name,
        ]);
    }
}
