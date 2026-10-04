<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One word for a job being over.
 *
 * The application had two. A batch was marked "done" - by the card's own "Move to done", or by the
 * nightly sweep that closes a batch whose steel has all been in for days - and a project was
 * "archived", which is the same idea applied to the other half of the pair. The two words were not
 * even kept apart consistently: the sweep that writes batches.done was itself called
 * "DeliveredBatchArchiving", and the menu item that runs it carried a box-archive icon, so
 * "archiving" named the done path as often as it named this column. That sweep is now
 * DeliveredBatchAutoDone, and this is the other half of the same tidy-up.
 *
 * So archive becomes done, and nothing else about it changes: still a boolean, still defaulting to
 * false, still the owner's own filing of a project, and still reversible - a project marked done by
 * mistake is reopened the way an archived one was restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->renameColumn('archive', 'done');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->renameColumn('done', 'archive');
        });
    }
};
