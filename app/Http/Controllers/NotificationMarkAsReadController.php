<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @deprecated
 */
class NotificationMarkAsReadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $id = $request->id;
        $notification = DatabaseNotification::findOrFail($id); // Replace with the actual notification ID
        $notification->markAsRead();

        return back();
    }
}
