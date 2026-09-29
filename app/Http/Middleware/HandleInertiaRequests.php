<?php

namespace App\Http\Middleware;

use App\Models\Batch;
use App\Models\Product;
use App\Models\Project;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'business' => $request->user() ? $request->user()->business : null,
                'isAdmin' => $request->user() && $request->user()->isAdmin(),
                //While impersonating, isAdmin() reads the impersonated user, so nothing else
                //on the page can tell that the session is not really theirs
                'impersonating' => (bool) $request->session()->get('impersonator_id'),
                //A user without a business is unexpected, but it must not 500 every
                //page - these are shared on every Inertia response
                'onboarded' => (bool) $request->user()?->business?->admin_setup_complete,
                'notifications' => (new NotificationService)->getUnreadNotifications($request->user()),
                "hasPastProjects" => (bool) $request->user()?->business?->batches()->inactive()->exists(),
            ],
            /*
             * Trial countdown and read-only state, for the banner that every authenticated page
             * carries. Provider-neutral by the time it gets here - see App\Billing\Billing - so
             * nothing in the front end knows or cares who takes the money.
             *
             * Memoised on the business for the request, so the nav, the banner and the billing page
             * all reading it is one resolution, not three.
             */
            'billing' => fn () => $request->user()?->business?->billingState()->toArray(),
            /*
             * Whether the platform catalogue has been seeded at all, which the nav turns into an
             * alert. platformCreated(), because a business's own private products are not a
             * catalogue - a BOM import still extracts nothing without one. exists(), not count():
             * this runs on every Inertia response.
             */
            'hasSeedImport' => Product::query()->platformCreated()->exists(),
            /*
             * Test mode, for the banner on the board and the marker in the nav. Shared on every
             * response because a user who cannot tell which set of data they are looking at is the
             * one failure this feature must not have.
             *
             * The two counts are what "Clear everything" would throw away. They cost nothing to
             * ask for while test mode is on - the global scope means a plain count() of projects
             * and batches is already a count of the sandbox's, and nothing else - and they are not
             * asked for at all while it is off.
             */
            'sandbox' => fn () => $this->sandboxState($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
                "project" => fn () => $request->session()->get('project'),
                //What the master materials screen said about the last edit it saved or refused
                'materials' => fn () => $request->session()->get('materials'),
                //What a JSON import would do, for review before any of it is applied
                'materialsImportPlan' => fn () => $request->session()->get('materialsImportPlan'),
                //The template form, filled in from a sample spreadsheet, with the checks run on it
                'templateProposal' => fn () => $request->session()->get('templateProposal'),
                //Every row the template in the form would extract from a sample, and its fate
                'templateTest' => fn () => $request->session()->get('templateTest'),
            ],
            'adminEmail' => config('env.admin_email'),
            "loginAvailable" => env("LOGIN_AVAILABLE"),
        ];
    }

    /**
     * @return array{active: bool, projects: int, batches: int}|null
     */
    private function sandboxState(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (! $user->sandbox_mode) {
            return ['active' => false, 'projects' => 0, 'batches' => 0];
        }

        return [
            'active' => true,
            'projects' => Project::query()->count(),
            'batches' => Batch::query()->count(),
        ];
    }
}
