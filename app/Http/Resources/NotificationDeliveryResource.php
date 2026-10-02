<?php

namespace App\Http\Resources;

use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationDelivery
 */
class NotificationDeliveryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /*
         * Spelled out rather than handing over the notifiable, for the reason UserResource gives:
         * a model serializes every column and every relation loaded on it, and the business loaded
         * here to render a domain carries its templates - screenshots and all - if anything upstream
         * has touched them.
         *
         * Can legitimately be null, and not only because notifiable is polymorphic: this log outlives
         * the row it points at, which is the point of a log. A user deleted tomorrow does not erase
         * the fact that they were emailed today, so the page has to be able to say "no recipient"
         * rather than fall over on it.
         */
        $recipient = $this->notifiable;

        return [
            'id' => $this->id,
            //'database' or 'mail' - what the filter and the pill are keyed on
            'channel' => $this->channel,
            //'Bell' or 'Email'
            'channelLabel' => $this->resource->channelLabel(),
            'type' => $this->resource->shortType(),
            'typeLabel' => $this->resource->typeLabel(),
            //The subject as sent. Null on every bell row, and on a mail row sent before this log
            //existed - there are none, but the column is nullable and the page must not assume
            'subject' => $this->subject,
            'recipient' => $recipient instanceof User ? [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'email' => $recipient->email,
                'business' => $recipient->business?->domain,
            ] : null,
            /*
             * Where the mail actually went. Shown beside the recipient only when the two disagree -
             * which is the one case this column exists for, a user who has corrected their address
             * since. See the migration.
             */
            'recipientEmail' => $this->recipient_email,
            /*
             * What it was about, where the payload says. Eight of the seventeen classes carry a
             * project_name - every one that chases a deadline or reports a colleague's press - and
             * the rest are about a business, a rack or a signup and have no project to name. So this
             * is a hint beside the type, not a description of the notification.
             */
            'projectName' => $this->payload['project_name'] ?? null,
            'sentAt' => $this->created_at,
        ];
    }
}
