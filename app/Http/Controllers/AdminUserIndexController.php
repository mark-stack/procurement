<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserIndexController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        /*
         * Eager loaded: UserResource reads business, business.templates and
         * business.suppliers, which was 3 queries per user against User::all().
         */
        $users = User::query()
            ->with(['business.templates', 'business.suppliers'])
            ->get();

        return Inertia::render('AdminUsersIndex', [
            'users' => UserResource::collection($users),
        ]);
    }
}
