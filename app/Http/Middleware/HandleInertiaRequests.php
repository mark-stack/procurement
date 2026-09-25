<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\PrerequisiteConditions\PrerequisiteConditions;
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
                //A user without a business is unexpected, but it must not 500 every
                //page - these are shared on every Inertia response
                'onboarded' => (bool) $request->user()?->business?->admin_setup_complete,
                'notifications' => (new NotificationService)->getUnreadNotifications($request->user()),
                "hasPastProjects" => (bool) $request->user()?->business?->batches()->inactive()->exists(),
            ],
            'hasSeedImport' => Product::count() > 0,
            'flash' => [
                'warning' => fn () => $request->session()->get('warning'),
                "project" => fn () => $request->session()->get('project'),
            ],
            'adminEmail' => config('env.admin_email'),
            "loginAvailable" => env("LOGIN_AVAILABLE"),
        ];
    }
}
