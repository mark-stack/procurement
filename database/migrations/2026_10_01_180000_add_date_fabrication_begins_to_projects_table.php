<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the shop starts fabricating the job - asked for, and required, as the project is created.
     *
     * The only date the business is actually sure of. date_materials_required is the date every
     * deadline on the board is measured back from, and it is nullable, never asked for by either
     * upload form, and carries "tentative" precisely because nobody knows it when the material list
     * arrives. Fabrication start is the fixed point it hangs off: materials have to be quoted,
     * ordered and delivered before the first cut, so this is the one answer a project manager can
     * always give on the day they upload the BOM.
     *
     * Nullable in the database although required in the form. The projects already on the board were
     * created before anybody was asked, and a NOT NULL would need a date invented for every one of
     * them - a made-up fabrication date is worse than a missing one, because nothing downstream can
     * tell the two apart. StoreProjectRequest is what makes it required, so every project created
     * from here on has a real answer, and UpdateProjectRequest lets an older project be given one
     * (and a mistyped one corrected) without forcing it.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('date_fabrication_begins')->nullable()->after('date_materials_required');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('date_fabrication_begins');
        });
    }
};
