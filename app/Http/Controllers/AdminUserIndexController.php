<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserIndexController extends Controller
{
    //Every user on the platform used to come back in one response, so the page got slower
    //with every signup and there was no way to look at only the recent ones
    private const USERS_PER_PAGE = 50;

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        /*
         * withCount, not with(): the page renders the number of templates and suppliers,
         * never the rows. Loading them shipped every template's base64 screenshot - up to
         * 750KB each, and twice per user row, because UserResource sent the business model
         * with its loaded relations as well as the relations themselves. Three users in one
         * business with two screenshots measured an 11.5MB response.
         *
         * The column list is explicit for the same reason: the whole business row went out
         * to render a domain and a status.
         */
        $users = User::query()
            //Relation, not Builder: an eager load closure is handed the relation itself
            ->with(['business' => fn (Relation $query) => $query
                ->select(['id', 'domain', 'admin_setup_complete'])
                ->withCount(['templates', 'suppliers']),
            ])
            /*
             * Newest first: this page exists to find new signups. It had no order at all,
             * so rows came back in whatever order the database chose and a new user could
             * appear anywhere in the list. id breaks the tie because a batch of users
             * created in the same second has identical timestamps.
             */
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::USERS_PER_PAGE)
            ->withQueryString();

        return Inertia::render('AdminUsersIndex', [
            'users' => UserResource::collection($users),
        ]);
    }
}
