<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Notifications\NewUserEmail;
use App\Rules\BusinessEmailDomain;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:'.User::class,
                new BusinessEmailDomain,
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        /*
         * The user was created first and given its business in a second save. If
         * anything between the two failed, the user was left with a null business_id
         * permanently, which then took down the whole admin users list.
         */
        $user = DB::transaction(function () use ($request) {
            //Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            //Find or Create business
            $domain = $user->getDomainFromEmail();

            /*
             * Unreachable behind the email rules above, and a guard rather than a check: a null
             * domain used to be passed straight to firstOrCreate, which created one nameless
             * business and then pooled every later unreadable address into it. Refusing the
             * registration is the only safe answer - there is no domain, so there is no business
             * this person can be said to belong to.
             */
            if ($domain === null) {
                throw ValidationException::withMessages([
                    'email' => 'Please use your work email address.',
                ]);
            }

            $business = Business::query()->firstOrCreate(
                [
                    'domain' => $domain,
                ],
                [
                    'name' => $domain,
                ],
            );

            /*
             * A new business is given no templates. Its uploads are matched against its own
             * templates and nothing else, so it can import nothing until an admin records one -
             * which is the workflow: the customer emails us the reports they export, and we
             * build a template per report on /admin/businesses/{business}/templates.
             *
             * A shared starting set was tried and is deliberately gone: four Tekla reports that
             * fit one customer's export settings are not what the next customer's file looks
             * like, and a template that half-matches reads columns off the wrong offsets.
             */

            //Assign business to user
            $user->business_id = $business->id;
            $user->save();

            return $user;
        });

        /*
         * Admin notify. By the flag rather than by the configured address: the address stopped
         * deciding who the admin is in the 2026_09_30 migration, and a lookup that still asked the
         * old question would mail whoever currently holds that email instead of the admin.
         *
         * Every admin, not first(). A signup is the start of work only we can do - the templates
         * that let this business import anything - so the notice has to reach whoever is going to
         * do it. With one admin these are the same query; the day a second one exists, first()
         * silently picks by id and the other never hears about a customer at all.
         */
        $admins = User::query()->where('is_admin', true)->get();

        if ($admins->isNotEmpty()) {
            $message = 'A new user signed up:';
            Notification::send($admins, new NewUserEmail($user, $message));
        }

        /*
         * Colleague notify happens on verification, not here. A business is every user whose email
         * domain matched, so this line put a stranger in every real employee's bell as their
         * colleague on the strength of an address nobody had checked. See
         * App\Listeners\AnnounceVerifiedColleague.
         */

        //Login
        event(new Registered($user));
        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
