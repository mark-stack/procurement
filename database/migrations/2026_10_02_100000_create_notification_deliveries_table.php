<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per notification per channel: the record of what this application actually sent.
 *
 * Nothing recorded an email. The notifications table is Laravel's database channel and holds the
 * bell's rows only, so a mail-only class - NewUserEmail, TrialEndingEmail, the welcome and login
 * link an admin sends by hand - left no trace anywhere once the queue worker was done with it.
 * notification_logs is not it either: that is a two-column "have I already sent this" mark kept by
 * two commands, with no channel, no recipient address and no subject.
 *
 * So this is a delivery log rather than a second copy of the bell. A notification going out by both
 * channels writes two rows sharing one notification_id, which is what lets the admin screen say
 * which of the two happened, and it is written from the one event Laravel fires per channel per
 * recipient - see App\Listeners\RecordNotificationDelivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            /*
             * Laravel's own id for the notification instance, shared by every channel it went out
             * on. Nullable and not a foreign key: the mail channel writes no notifications row to
             * point at, and the row a database send does write is the bell's to mark read or clear.
             * This log is not allowed to disappear with it.
             */
            $table->uuid('notification_id')->nullable()->index();

            //Users today. morphs() because Laravel's notifiable is polymorphic and this is a
            //straight record of what it handed the channel
            $table->morphs('notifiable');

            //'mail' or 'database', as the channel names itself. Stored as sent rather than as a
            //boolean, so a third channel needs no migration to be legible here
            $table->string('channel');

            //The notification class, spelled the way notifications.type spells it, so a row here
            //and a row there can be recognised as the same thing
            $table->string('type');

            /*
             * Where the mail went, as at the time it went. The user's current address is one join
             * away and is a different fact: a reminder sent to somebody before they corrected their
             * email did not reach the corrected one, and an audit log that quietly updates itself is
             * no use for answering "did they ever get it".
             */
            $table->string('recipient_email')->nullable();

            //The subject line that was actually sent, read off the built message - several of these
            //classes set none and take Laravel's wording from the class name. Null for the bell
            $table->string('subject')->nullable();

            //What the notification carried: project_id, project_name, business_id and the like.
            //Null for the three mail-only classes, which have no toArray() at all
            $table->json('payload')->nullable();

            //The two questions the admin screen asks: this recipient's deliveries, and this
            //channel's. created_at because the list is newest first
            $table->index(['notifiable_id', 'channel']);
            $table->index('created_at');
        });

        /*
         * Every bell notification already sent, brought in as the database-channel deliveries they
         * were. Without this the screen opens empty on a platform that has been sending for months,
         * which reads as "nothing has ever been sent" rather than "the log starts today".
         *
         * Only the bell can be backfilled. There is no record of a single sent email to recover, and
         * inventing one would be worse than the gap - so mail history genuinely does start here.
         */
        DB::table('notifications')->orderBy('id')->chunk(500, function ($notifications) {
            $rows = [];

            foreach ($notifications as $notification) {
                $rows[] = [
                    'notification_id' => $notification->id,
                    'notifiable_type' => $notification->notifiable_type,
                    'notifiable_id' => $notification->notifiable_id,
                    'channel' => 'database',
                    'type' => $notification->type,
                    //Not resolved from the user: see recipient_email above. The address these were
                    //sent to is not known, and the bell was never sent to an address at all
                    'recipient_email' => null,
                    'subject' => null,
                    'payload' => $notification->data,
                    'created_at' => $notification->created_at,
                    'updated_at' => $notification->updated_at,
                ];
            }

            DB::table('notification_deliveries')->insert($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
