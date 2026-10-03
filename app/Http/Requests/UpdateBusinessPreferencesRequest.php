<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The two lead times a business runs on, submitted from /profile.
 *
 * Both are whole days, and both are allowed to be zero: a fabricator who gets prices back the same
 * afternoon, or who collects off the merchant's rack, has a lead time of none - and a floor of one
 * would have the deadline notifications warning them a day early for ever.
 *
 * Capped at a year, which is not a figure anybody will reach. It is there because these two are added
 * together and counted back from a materials date (Project::criticalPathDeadline), so a number with a
 * few extra digits in it would put every project on the board permanently overdue, with nothing on
 * the screen explaining why.
 *
 * No authorize() of its own: the controller writes the signed-in user's own business and takes no id
 * from the request, so there is no other business this can reach. See BusinessPreferencesController.
 */
class UpdateBusinessPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quoting_days' => ['required', 'integer', 'min:0', 'max:365'],
            'delivery_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }

    /**
     * Worded as the form labels them, rather than as the columns are named.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quoting_days' => 'quoting time',
            'delivery_days' => 'delivery time',
        ];
    }
}
