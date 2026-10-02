<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * The spreadsheet a material list came out of, kept.
         *
         * Until now it was not kept at all: ProductController read the uploaded temp file straight
         * into Excel::toArray() and let it go, and the only trace a file had ever existed was the
         * rows it produced. That is fine right up to the moment somebody uploads the wrong revision
         * onto the open batch, which is a thing that happens - a drawing office issues rev C, the
         * draftsman uploads rev B by mistake, and the only way back was to find that file's rows in a
         * table of several projects' steel and tick them off one at a time.
         *
         * So the file is stored and every row it produced points back at it. Deleting the row here is
         * how you take a whole upload off the batch again - see MaterialListFile::deleteWithRows,
         * which refuses while any of that steel has been quoted or ordered.
         *
         * Nothing backfills: rows imported before this migration have no file to point at, and the
         * Bill of Materials modal says so rather than pretending they arrived from nowhere.
         */
        Schema::create('material_list_files', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            //The job it was uploaded against. A file belongs to one project, as its rows do
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            //Who uploaded it, which on an on-behalf-of upload is not the project manager.
            //Nullable so losing a user account never takes the file with it
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            //Where it sits on the private disk. Never exposed - downloads go through the controller.
            //Nullable: a full disk must not cost the customer their import, so a file that could not
            //be stored still gets a row, and the row says there is nothing to download
            $table->string('path')->nullable();
            //What it was called when it arrived, which is what every screen shows
            $table->string('original_filename');
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
        });

        Schema::table('raw_material_quotes', function (Blueprint $table) {
            /*
             * Nullable twice over: for every row imported before this existed, and for the file whose
             * row is deleted out from under it. The delete path takes the materials first and the file
             * second, so the null is a safety net rather than a state anything reaches on purpose.
             */
            $table->foreignId('material_list_file_id')
                ->nullable()
                ->after('project_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('raw_material_quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_list_file_id');
        });

        Schema::dropIfExists('material_list_files');
    }
};
