<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What changed, on the rows a fabricator's auditor asks about.
     *
     * Nothing in this application recorded a change to anything. A corrected quantity, a re-matched
     * product, a deleted BOM line, a supplier taken off the list, a template edited under a customer
     * whose spreadsheets it reads - every one of them left the row looking as though it had always
     * said what it says now, with updated_at as the only clue and one value to carry it.
     * AdminMaterialUpdateController even computes the right answer - $product->getDirty() - and puts
     * it in a flash message.
     *
     * The shape is deliberately not "a history of the row". It is a list of events, each one naming
     * the columns that moved, who moved them and when. That is what ISO 9001 7.5.3.2 asks of
     * documented information (protected from unintended alteration, and attributable) and what 8.5.6
     * asks of a change to production arrangements (the results of the review, and the person
     * authorising it, retained).
     *
     * Append-only, and in three places so that it is true rather than intended:
     *   - no updated_at: there is no second version of an event
     *   - App\Models\RecordChange refuses update() and delete() outright
     *   - nothing in the application reads a route that would write one by hand
     *
     * All three are in PHP, which is the known limit of the arrangement: they catch the honest
     * mistake and not a deliberate rewrite by anybody holding a deploy. A MySQL grant covering that
     * was carried here for a week and dropped on 2026-10-08 without ever being applied to a server;
     * docs/records-retention.md says why, and what it would take to put back.
     */
    public function up(): void
    {
        Schema::create('record_changes', function (Blueprint $table) {
            $table->id();

            /*
             * created_at alone, and indexed because every read of this table is "what happened to
             * this row" or "what happened this month".
             */
            $table->timestamp('created_at')->nullable()->index();

            /*
             * The row this happened to, by morph ALIAS rather than class name - see the morph map in
             * AppServiceProvider. A log that stores "App\Models\Order" stops resolving the day that
             * class is moved, and the one thing this table must survive is a refactor.
             */
            $table->string('record_type', 100);
            $table->unsignedBigInteger('record_id');

            //created | updated | deleted
            $table->string('event', 20);

            /*
             * {column: [before, after]} for an update, and the whole row for a create or a delete.
             *
             * The delete case is the one that earns the column: once the row is gone this is the only
             * place that says what it held, which is the difference between "a certificate was
             * removed" and "somebody removed cert-4471882.pdf from the order to Southern Steel".
             */
            $table->json('changes');

            /*
             * Who did it. Nullable and nullOnDelete for the same reason material_certificates.user_id
             * is: losing an account must never take the record of what that account did with it.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The admin who was actually driving, where this happened under impersonation.
             *
             * Everything done while impersonating is stored against the impersonated user - that is
             * what makes the feature useful and what made the records lie. An order placed by an
             * admin inside a customer's account read as the customer placing it, and the only trace
             * was a Log::info line in a rotating file. This column is that trace, in the database,
             * next to the change it explains.
             */
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Whose records these are, so the log can be read one customer at a time. Resolved from
             * the row where the row knows (templates carry a business_id) and from the acting user
             * otherwise. Null for a platform-level change such as a master catalogue edit, which
             * belongs to no business by design.
             */
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();

            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_changes');
    }
};
