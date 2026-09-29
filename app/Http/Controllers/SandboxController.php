<?php

namespace App\Http\Controllers;

use App\Services\SandboxCleaner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SandboxController extends Controller
{
    /**
     * Switch this user - and only this user - into their own sandbox.
     *
     * Their live projects and batches stay exactly where they are; the board simply stops showing
     * them and starts showing whatever gets made from here. Nothing is copied and nothing is
     * hidden from anyone else.
     */
    public function enter(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->sandbox_mode = true;
        $user->save();

        return redirect()
            ->route('projects.index')
            ->with('success', 'Test mode is on. Projects and batches you create now are yours alone, and can be thrown away at any time.');
    }

    public function leave(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->sandbox_mode = false;
        $user->save();

        /*
         * Back to the real board rather than back to where they were: a page opened in test mode
         * is showing test rows, and several of them are bound to a project or a batch the live
         * board has no route to.
         */
        return redirect()
            ->route('projects.index')
            ->with('success', 'Back on your real projects. Anything you made in test mode is still there next time you switch.');
    }

    /**
     * Throw away everything made in test mode.
     *
     * Not gated on being in test mode. Somebody who has switched back to real work is exactly the
     * person who wants the mess gone, and the cleaner only ever touches rows stamped with their own
     * id - see App\Services\SandboxCleaner.
     */
    public function clear(Request $request, SandboxCleaner $cleaner): RedirectResponse
    {
        $cleared = $cleaner->clear($request->user());

        if ($cleared['projects'] === 0 && $cleared['batches'] === 0) {
            return back()->with('success', 'There was no test data to clear.');
        }

        return back()->with('success', sprintf(
            'Test data cleared - %d %s and %d %s, with everything quoted, ordered and nested against them.',
            $cleared['projects'],
            $cleared['projects'] === 1 ? 'project' : 'projects',
            $cleared['batches'],
            $cleared['batches'] === 1 ? 'batch' : 'batches',
        ));
    }
}
