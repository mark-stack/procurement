<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who uploaded the material list, when that is not the project manager it is for.
     *
     * projects.user_id is the project manager - the name the board draws, the person every deadline
     * notification goes to, and the only distinction this application draws between colleagues. It
     * was also, unavoidably, whoever happened to be logged in when the spreadsheet arrived. In a
     * fabricator with a drawing office that is the draftsman: BOMs come out of the model and get
     * uploaded by the person who detailed the job, for a project manager who never touches the
     * upload page. Every one of those projects sat on the board under the draftsman's name, and the
     * manager running the job could not edit it, mark it done or be reminded about its deadline.
     *
     * So user_id becomes what it says - the manager the upload is for, chosen on the upload page -
     * and this records who actually did it. Both are needed:
     *
     *  - The manager, because the board, the reminders and the owner-only gates are all about whose
     *    job it is.
     *  - The uploader, because a job's materials do not arrive in one file on one day. The draftsman
     *    who uploaded the first list is the one the second one reaches, and an import that stops at
     *    a price book clarification has to be finishable by the person holding the spreadsheet.
     *    Without this column that work could only be done by a manager who never had the file.
     *
     * Nullable, and null means the plain case: the manager uploaded it themselves. Every project that
     * existed before this was its own uploader, so there is nothing to backfill and no row where the
     * absence has to be guessed at.
     *
     * Restricting rather than nullOnDelete, like user_id and sandbox_user_id already are. "Uploaded
     * by somebody who no longer exists" is worth keeping; silently becoming "uploaded by the manager"
     * is a lie the certificate trail would carry.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('sandbox_user_id')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn('created_by_user_id');
        });
    }
};
