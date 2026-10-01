<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Onboarding is over.
     *
     * This column meant "an admin has read this customer's spreadsheets, written their import
     * templates and switched them on". Until that was true, BusinessReadyMiddleware held every page
     * a customer would use and sent them to a page asking them to email us example bills of
     * materials. We quoted two business days for it.
     *
     * An upload that matches no template now writes its own template, tests it with the real importer
     * against the file that triggered it, and saves it if it passes - see TemplateLearningService. So
     * the state this column described has nowhere left to exist: there is nothing an admin has to do
     * between a signup and that business importing, and the first spreadsheet they upload is the thing
     * that sets them up.
     *
     * Dropping it rather than leaving it set, because a column nothing reads is a column somebody
     * gates on by accident later. What went with it:
     *
     *  - BusinessReadyMiddleware, and the /onboarding page it sent people to
     *  - ActivateBusinessController and DeactivateBusinessController, which were the only writers
     *  - Business::scopeActivated(), which kept the trial reminders away from businesses that were
     *    waiting on us - a distinction that no longer exists, because nobody waits on us
     *  - the 'onboarded' Inertia prop, which decided whether the nav showed the product or the
     *    onboarding link
     *
     * The trial is the part worth saying out loud. It was started by activation, because a trial that
     * ran while a customer could not import anything was a trial spent on our queue. Business::
     * booted() starts it at creation, which is registration, and that is now honest: the minute the
     * row exists is the minute the product works.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('admin_setup_complete');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            /*
             * Default true, where the original column defaulted false. Reversing this migration on a
             * live database means putting the gate back in front of customers who have been importing
             * for weeks, and defaulting it the other way would lock every one of them out of their own
             * board until somebody pressed a button that no longer exists.
             */
            $table->boolean('admin_setup_complete')->default(true)->after('domain');
        });
    }
};
