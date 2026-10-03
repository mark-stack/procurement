<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBusinessPreferencesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;

/**
 * The settings on /profile that belong to the business rather than to the person reading the page.
 *
 * Quoting time and delivery time: the two halves of the critical path every deadline notification is
 * counted back from (Project::criticalPathDays). They sit on the profile page because that is where a
 * fabricator's own settings are, and there is no other business-settings screen in the application -
 * the nesting figures are on an admin screen, which customers do not see.
 *
 * Any member of the business may set them, and the form says so on its face. There is no owner or
 * manager role here: a business is a handful of people who already share a board, each other's
 * projects and each other's batches, and inventing a permission for this one pair of numbers would be
 * the only place in the application that had one. What the form must not do is change them quietly,
 * which is why it names who else it affects.
 */
class BusinessPreferencesController extends Controller
{
    public function update(UpdateBusinessPreferencesRequest $request): RedirectResponse
    {
        $business = $request->user()->business;

        /*
         * A user with no business is BusinessReadyMiddleware's problem, not this one's - and there is
         * nothing here to write to. Refusing rather than creating one: a business is joined by email
         * domain at registration, and minting a second one here would split a fabricator in two.
         */
        abort_if($business === null, 403);

        /*
         * The business the signed-in user belongs to, and no id from the request anywhere - so there
         * is no other business's settings this route can be pointed at.
         */
        $business->update($request->validated());

        return Redirect::route('profile.edit');
    }
}
