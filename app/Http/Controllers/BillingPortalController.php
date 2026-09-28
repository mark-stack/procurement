<?php

namespace App\Http\Controllers;

use App\Billing\Billing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Hand the customer over to the provider's own billing portal.
 *
 * Changing a card, reading past invoices and cancelling all live there rather than in this app, so
 * that there is one account of what was cancelled and when, and so that a second provider does not
 * need this application to grow a cancellation screen it cannot implement.
 */
class BillingPortalController extends Controller
{
    public function __construct(private readonly Billing $billing) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $business = $request->user()?->business;

        if ($business === null) {
            return redirect()->route('onboarding');
        }

        try {
            $url = $this->billing->provider()->manageUrl($business, route('billing.index'));
        } catch (Throwable $e) {
            Log::error('Billing portal could not be opened', [
                'business_id' => $business->id,
                'provider' => $this->billing->provider()->name(),
                'exception' => $e->getMessage(),
            ]);

            $url = null;
        }

        //No portal: an invoice customer, or a provider that has none. Both are settled over email.
        if ($url === null) {
            return redirect()
                ->route('billing.index')
                ->with('warning', 'This account is billed by invoice, so there is no online portal. Email '.config('billing.invoice_email').' and it will be sorted out.');
        }

        return Inertia::location($url);
    }
}
