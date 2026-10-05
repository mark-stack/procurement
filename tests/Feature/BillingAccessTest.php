<?php

use App\Http\Middleware\BillingWriteAccessMiddleware;
use Illuminate\Support\Facades\Route;

/**
 * What a lapsed account can and cannot do.
 *
 * Read-only rather than locked out: a fabricator halfway through a job has cutting lists in here
 * that the saw is working from, and taking those away over a lapsed card would be both the wrong
 * kind of leverage and the destruction of the only reason they have to come back and pay.
 */
it('would be a disaster if a lapsed account lost access to work it had already done', function () {
    $business = lapsedTrialBusiness();
    $user = createUser(1, $business, false, true);

    /*
     * Everything they built stays open. Not an exhaustive list of the application's pages, but one
     * of each kind: the board, past batches, the rack and the price book.
     */
    foreach (['dashboard', 'past.batches.index', 'offcuts.index', 'pricebook'] as $page) {
        $this->actingAs($user)
            ->get(route($page))
            ->assertStatus(200);
    }
});

it('would be a disaster if a lapsed account could still change things', function () {
    $business = lapsedTrialBusiness();
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->patch(route('business.preferences.update'), ['quoting_days' => 9, 'delivery_days' => 9])
        ->assertRedirect(route('billing.index'))
        ->assertSessionHas('warning');

    expect($business->fresh()->quoting_days)->not->toBe(9);
});

it('would be a disaster if a lapsed account could still nest a batch', function () {
    /*
     * The batches routes sit outside the BusinessReadyMiddleware group, so they carry the billing
     * gate separately - and nesting is the most expensive write in the application, so it is the
     * last thing that should stay open.
     */
    $business = lapsedTrialBusiness();
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->post(route('batches.store'), [])
        ->assertRedirect(route('billing.index'));
});

it('would be a disaster if the billing gate blocked a business that was still on trial', function () {
    $business = createBusiness('On trial');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->patch(route('business.preferences.update'), ['quoting_days' => 9, 'delivery_days' => 9])
        ->assertRedirect();

    expect($business->fresh()->quoting_days)->toBe(9);
});

it('would be a disaster if a read-only account could not reach the page that fixes it', function () {
    /*
     * The billing routes sit outside BillingWriteAccessMiddleware on purpose: subscribing is the one
     * write a read-only account has to be able to make, so the page that fixes an expired trial must
     * not be behind the thing an expired trial blocks.
     */
    fakeBillingProvider();

    $business = lapsedTrialBusiness();
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('billing.index'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->where('billing.readOnly', true)
            ->where('billing.status', 'TRIAL_EXPIRED')
        );

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['plan' => 'weekly'])
        ->assertRedirect('https://checkout.test/session');
});

it('would be a disaster if checkout sent the customer anywhere but the provider', function () {
    $fake = fakeBillingProvider();

    $business = createBusiness('Buying');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['plan' => 'weekly'])
        ->assertRedirect('https://checkout.test/session');

    expect($fake->checkoutsStarted)->toBe(['weekly']);
});

it('would be a disaster if the annual plan stopped being sold on an invoice', function () {
    /*
     * The two plans are bought differently: the weekly one by card, the annual one on an invoice
     * with a purchase order against it. So a live, working provider must not swallow the annual
     * plan - no price id exists for it anywhere, and a checkout session for it would be a session
     * nobody can pay.
     */
    $fake = fakeBillingProvider();

    $business = createBusiness('Invoiced yearly');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('billing.index'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            //Two plans, and the page is told which of them ends in a card form
            ->has('plans', 2)
            ->where('plans.0.key', 'weekly')
            ->where('plans.0.checkout', 'provider')
            ->where('plans.1.key', 'annual')
            ->where('plans.1.checkout', 'invoice')
            ->where('hostedCheckout', true)
        );

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['plan' => 'annual'])
        ->assertRedirect(route('billing.invoice', ['plan' => 'annual']));

    expect($fake->checkoutsStarted)->toBe([]);
});

it('would be a disaster if the annual plan were not the discount it is sold as', function () {
    /*
     * The billing page badges the annual plan against fifty-two weeks of the weekly one. Priced
     * wrong, that badge either undersells the plan or claims a discount that is not there.
     */
    $plans = app(App\Billing\Plans::class);

    $weekly = $plans->findOrFail('weekly');
    $annual = $plans->findOrFail('annual');

    expect($weekly->amount)->toBe(3900);
    expect($annual->amount)->toBe(170000);
    expect((int) round((1 - $annual->amount / ($weekly->amount * 52)) * 100))->toBe(16);
});

it('would be a disaster if a made-up plan reached the payment provider', function () {
    $fake = fakeBillingProvider();

    $business = createBusiness('Buying');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['plan' => 'free-forever'])
        ->assertStatus(404);

    expect($fake->checkoutsStarted)->toBe([]);
});

it('would be a disaster if a plan the provider cannot sell were offered for sale', function () {
    /*
     * A half-configured provider - Stripe keys in place but no price id for a plan - must not put a
     * Subscribe button in front of anyone, because the checkout behind it cannot be built.
     *
     * The invoice plan is the exception, and deliberately so: nothing about it goes through the
     * provider, so a provider that cannot sell anything still leaves a way to buy.
     */
    $fake = fakeBillingProvider();
    $fake->sells = false;

    $business = createBusiness('Buying');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('billing.index'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->has('plans', 1)
            ->where('plans.0.key', 'annual')
        );

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['plan' => 'weekly'])
        ->assertRedirect(route('billing.index'))
        ->assertSessionHas('warning');

    expect($fake->checkoutsStarted)->toBe([]);
});

it('would be a disaster if an outage at the payment provider took the billing page down with it', function () {
    /*
     * The provider is a third party over the network: a bad price id, an expired key or an outage all
     * arrive as an exception. A 500 on the one page whose job is to take the customer's money is the
     * worst possible place for one.
     */
    $fake = fakeBillingProvider();
    $fake->failWith = new RuntimeException('Stripe is having a day');

    $business = createBusiness('Buying');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['plan' => 'weekly'])
        ->assertRedirect(route('billing.index'))
        ->assertSessionHas('warning');
});

it('would be a disaster if an invoice customer were sent to a billing portal that does not exist', function () {
    $fake = fakeBillingProvider();
    $fake->manageUrl = null;

    $business = createBusiness('Invoiced');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('billing.manage'))
        ->assertRedirect(route('billing.index'))
        ->assertSessionHas('warning');
});

it('would be a disaster if managing billing did not reach the provider portal', function () {
    fakeBillingProvider();

    $business = createBusiness('Paying');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('billing.manage'))
        ->assertRedirect('https://portal.test/session');
});

it('would be a disaster if asking for an invoice stopped working', function () {
    /*
     * Plenty of fabricators will not put a company card into a web form, and this was how the
     * product was sold before any provider existed. It stays reachable whichever driver is live.
     */
    $business = createBusiness('Invoiced');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('billing.invoice', ['plan' => 'annual']))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->where('plan.key', 'annual')
            ->where('invoiceEmail', config('billing.invoice_email'))
        );

    $this->actingAs($user)
        ->get(route('billing.invoice', ['plan' => 'nonsense']))
        ->assertStatus(404);
});

it('would be a disaster if subscribing did nothing on an installation with no payment provider', function () {
    /*
     * No fake and no Stripe: the manual driver, which is what the test environment and a
     * freshly deployed installation both run on. Subscribing has to lead somewhere even then, and
     * where it leads is the invoice request - the only checkout it has.
     */
    expect(config('billing.driver'))->toBe('manual');

    $business = lapsedTrialBusiness();
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('billing.index'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            //Every plan is still purchasable - by invoice, which needs no price id anywhere
            ->has('plans', count(config('billing.plans')))
            //...and the page has to know that, so the button does not promise a card form
            ->where('hostedCheckout', false)
        );

    $this->actingAs($user)
        ->post(route('billing.checkout'), ['plan' => 'weekly'])
        ->assertRedirect(route('billing.invoice', ['plan' => 'weekly']));
});

it('would be a disaster if a new write route were added outside the billing gate', function () {
    /*
     * The gate refuses by HTTP verb, so it cannot fall out of date as routes are added - but only
     * for routes it is actually attached to. This walks the route table instead of listing examples,
     * so a new POST added to a group that has no gate fails here rather than in production as a
     * lapsed account quietly still working.
     *
     * Anything genuinely meant to stay open goes in $allowed below, with the reason.
     */
    $allowed = [
        //Subscribing is the one write a read-only account has to be able to make
        'billing.checkout',
        //Your own account is yours whatever you have paid: name, password, deleting it
        'profile.update',
        'profile.destroy',
        'password.update',
        'confirm-password',
        'logout',
        'verification.send',
        //Dismissing a notification changes nothing anyone is being billed for
        'mark.notification.status',
        /*
         * Test mode. Switching in and out writes one boolean on the user, and being stuck in a
         * sandbox with no way back to the real board would be a far worse read-only experience
         * than anything the gate is protecting. Clearing only ever deletes the caller's own test
         * rows - the live board cannot be reached from it at all.
         */
        'sandbox.enter',
        'sandbox.leave',
        'sandbox.clear',
    ];

    $unguarded = [];
    $guarded = [];

    foreach (Route::getRoutes() as $route) {
        $writeMethods = array_values(array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']));

        if ($writeMethods === []) {
            continue;
        }

        $middleware = $route->gatherMiddleware();

        //Only routes a logged-in customer can reach. Admin routes are gated by AdminMiddleware
        //instead, and an admin is us rather than a subscriber.
        if (! in_array('auth', $middleware, true)) {
            continue;
        }

        $name = $route->getName();

        if ($name !== null && str_starts_with($name, 'admin.')) {
            continue;
        }

        //Unnamed routes are identified by uri, which is what the allowlist holds for them
        $identifier = $name ?? $route->uri();

        if (in_array($identifier, $allowed, true)) {
            continue;
        }

        if (in_array(BillingWriteAccessMiddleware::class, $middleware, true)) {
            $guarded[] = $identifier;
        } else {
            $unguarded[] = $identifier.' ['.implode(',', $writeMethods).']';
        }
    }

    expect($unguarded)->toBe([]);

    /*
     * And the other half of the assertion: that the walk above found anything at all. A filter that
     * quietly matches nothing - a renamed middleware class, a changed group, gatherMiddleware()
     * returning something else - would otherwise pass this test for the rest of time while the gate
     * was attached to nothing.
     */
    expect(count($guarded))->toBeGreaterThan(15);
});

it('would be a disaster if the gate refused a download because of its own strictness', function () {
    /*
     * The rule is about verbs, and every download in this application is a GET. Pinned because the
     * whole promise of read-only is that the cutting lists keep coming out of the printer.
     */
    $business = lapsedTrialBusiness();
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('download.usage.data'))
        ->assertStatus(200);
});
