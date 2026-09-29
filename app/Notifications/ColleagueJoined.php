<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Somebody new registered against your business's email domain.
 *
 * Separate from NewUserEmail, which it used to share. NewUserEmail is the signup alert that goes to
 * the platform admin's inbox and should keep going there; this is a colleague being told a colleague
 * arrived, which is a red dot on the bell and nothing more. Sharing one class meant the two could
 * not have different channels, and it meant the bell entry was worded as an admin alert - it showed
 * the new person's email address, because that is what the admin needs.
 *
 * Not queued: registration sends it inside the request, and a bell entry is not worth a queue
 * worker being up for it. See also the deliberate absence of an invitation flow - a business is
 * every user whose email domain matched, so this notification is the only thing that tells the
 * existing staff that the headcount changed.
 */
class ColleagueJoined extends Notification
{
    public function __construct(
        public User $colleague,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'colleague_id' => $this->colleague->id,
            'colleague_name' => $this->colleague->name,
        ];
    }
}
