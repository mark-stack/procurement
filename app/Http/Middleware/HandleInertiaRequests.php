<?php

namespace App\Http\Middleware;

use App\Models\Product;
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
                "business" => $request->user()?->business,
                "isAdmin" => $request->user() && $request->user()->isAdmin(),
                "onboarded" => $request->user() && $request->user()->business->admin_setup_complete,
                "notifications" => (new NotificationService())->getNotifications($request->user()),
            ],
            "hasSeedImport" => Product::count() > 0,
            'flash' => [
                'warning' => fn () => $request->session()->get('warning'),
                'downloadedData' => fn () => $request->session()->get('downloadedData'),
            ],
            "adminEmail" => env("ADMIN_EMAIL"),
        ];
    }
}
