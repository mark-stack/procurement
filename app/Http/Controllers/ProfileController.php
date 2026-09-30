<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        /*
         * Refused before anything happens, not attempted and survived.
         *
         * projects.user_id, batches.user_id, quotes.user_id and orders.user_id are all restricting
         * foreign keys, so this delete throws for any account that has ever created a project - which
         * is every real account. And it used to throw *after* Auth::logout(), so pressing "Delete
         * Account" signed the user out, answered with a 500, and left the session it never reached
         * the invalidate() for. The account was still there; nothing said so.
         *
         * Refusing is the right answer rather than cascading. This is a shared workspace: a user's
         * projects sit in colleagues' Nesting column, their batches hold the steel those colleagues
         * ordered, and their orders carry the mill certificates behind material already cut and
         * installed. There is no version of "delete my account" that should take that with it, and a
         * business whose last member deleted themselves would leave its suppliers, templates and
         * price book owned by nobody.
         */
        $blocking = $this->workThatOutlivesTheAccount($user);

        if ($blocking !== []) {
            throw ValidationException::withMessages([
                'account' => 'This account cannot be deleted while it still owns '
                    .$this->readAsList($blocking).'. That work is part of your business\'s'
                    .' records - its board, its orders and its material certificates - and deleting'
                    .' the account would take it with it. Ask an administrator to deactivate the'
                    .' business instead, or hand the projects over first.',
            ]);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * What this account owns that a delete would have to destroy, counted and named.
     *
     * Every count lifts the sandbox scope. Projects and batches are scoped to the mode the user is
     * currently in, so counting them normally is how somebody in live mode gets told they are clear
     * to delete and then hits the foreign key on their own test rows - the exact 500 this exists to
     * prevent. Quotes and orders carry no flag of their own and are reached through a batch, so they
     * need no lifting.
     *
     * @return array<int, string>
     */
    private function workThatOutlivesTheAccount(User $user): array
    {
        $counts = [
            'project' => $user->projects()->withoutGlobalScope('sandbox')->count(),
            'batch' => $user->batches()->withoutGlobalScope('sandbox')->count(),
            'quote' => $user->quotes()->count(),
            'order' => $user->orders()->count(),
        ];

        $described = [];
        foreach ($counts as $noun => $count) {
            if ($count > 0) {
                $described[] = $count.' '.Str::plural($noun, $count);
            }
        }

        return $described;
    }

    /**
     * "3 projects, 1 batch and 2 orders" - so the refusal names what is actually in the way.
     *
     * @param  array<int, string>  $items
     */
    private function readAsList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
