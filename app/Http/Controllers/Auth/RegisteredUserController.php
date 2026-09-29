<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Notifications\NewUserEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules;
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
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
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

        //Admin notify
        $adminUser = User::query()->where('email', config('env.admin_email'))->first();
        if ($adminUser) {
            $message = 'A new user signed up:';
            Notification::send($adminUser, new NewUserEmail($user, $message));
        }

        //Login
        event(new Registered($user));
        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
