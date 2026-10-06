<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNestingSettingsRequest;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;

/**
 * Write one business's nesting figures.
 *
 * The other half of AdminNestingAlgorithmController, which explains what each of these buys and
 * until now was the only thing that could read them. See UpdateNestingSettingsRequest for why
 * writing them is safe at any time, and for the one constraint between two of them that is
 * refused rather than reported.
 */
class AdminNestingSettingsController extends Controller
{
    public function __invoke(UpdateNestingSettingsRequest $request, Business $business): RedirectResponse
    {
        $business->fill($request->settings());

        if (! $business->isDirty()) {
            return back()->with('nesting', [
                'ok' => true,
                'message' => 'Nothing changed.',
            ]);
        }

        /*
         * Named in the message rather than counted. These settings are edited one or two at a time
         * in response to a question somebody has asked about the nest - "why is it binning these" -
         * and knowing which two moved is most of the answer when the next person asks.
         */
        $changed = array_keys($business->getDirty());
        $business->save();

        return back()->with('nesting', [
            'ok' => true,
            'message' => sprintf(
                'Saved %s. Nests from here on use the new figures; everything already nested keeps the figures it was run on.',
                implode(', ', array_map(fn (string $column): string => str_replace('_', ' ', $column), $changed)),
            ),
        ]);
    }
}
