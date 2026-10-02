<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One notification, on one channel, to one recipient.
 *
 * Written by App\Listeners\RecordNotificationDelivery and never by application code - it is a record
 * of a send that already happened, so there is nothing here that creates, retries or resends one.
 *
 * @property string|null $notification_id
 * @property string $channel
 * @property string $type
 * @property string|null $recipient_email
 * @property string|null $subject
 * @property array<string, mixed>|null $payload
 */
class NotificationDelivery extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationDeliveryFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The channels this log knows how to label, in the order the admin screen offers them.
     *
     * A channel not listed here still records and still lists - the column stores whatever the
     * channel called itself - it simply gets its own name as its label. The filter pills are built
     * from this, so an unlabelled channel is reachable by url and not by a click, which is the right
     * way round for something nothing in this application sends yet.
     *
     * @return array<string, string>
     */
    public static function channelLabels(): array
    {
        return [
            'database' => 'Bell',
            'mail' => 'Email',
        ];
    }

    public function channelLabel(): string
    {
        return self::channelLabels()[$this->channel] ?? $this->channel;
    }

    /**
     * The notification class without its namespace: what the type column of the bell's own table
     * holds, minus the part that is the same on every row.
     */
    public function shortType(): string
    {
        return class_basename($this->type);
    }

    /**
     * The class name as something to read.
     *
     * The trailing "Email" comes off, and deliberately: eleven of these classes are named for the
     * channel they were originally written for, five of them now send by both, and a row labelled
     * "Quote Due Email" beside a pill reading "Bell" contradicts itself. The pill is the authority on
     * the channel - this says what the notification is about.
     */
    public function typeLabel(): string
    {
        return Str::headline(Str::beforeLast($this->shortType(), 'Email'));
    }

    /**
     * @param  Builder<NotificationDelivery>  $query
     * @return Builder<NotificationDelivery>
     */
    public function scopeChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }
}
