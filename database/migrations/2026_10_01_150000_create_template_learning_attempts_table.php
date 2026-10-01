<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The uploads that matched no template and could not be made to match one.
     *
     * A customer's spreadsheet that matches nothing is now read, described and tested on the spot,
     * and if the test passes a template is recorded and the file imports. When it does not pass,
     * what used to happen was a warning telling the customer to email the file to support - which
     * is a request for them to do the work of reporting a failure, and it throws away everything
     * the attempt just worked out.
     *
     * So the attempt is kept instead. The file, what was proposed for it, and every check that was
     * asked and what it answered. An admin opens the row, gets the form filled in with the proposal
     * that failed, downloads the same file it was proposed from, and finishes it by hand - which is
     * the job they were doing before, minus the part where they had to ask for the file.
     *
     * No screenshot column, unlike templates. A template's screenshot is evidence of what the
     * record was calibrated against and outlives the file; an attempt holds the file itself, so a
     * picture of it would be a second copy of the same thing at 750KB a row.
     */
    public function up(): void
    {
        Schema::create('template_learning_attempts', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            //Whose spreadsheet it was. cascade: an attempt is meaningless without the business
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            /*
             * Who uploaded it, and what they were uploading it into. Both nullOnDelete: the attempt
             * is a record of a format we could not read, and that stays true after the estimator
             * leaves or the project they were quoting is deleted.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            //What the customer called the file, and where the copy of it is kept
            $table->string('file_name');
            $table->string('sample_path')->nullable();

            /*
             * Why there is no template. REFUSED is the test running and failing; the other two are
             * it never running at all - see TemplateLearningEnums.
             */
            $table->string('outcome');

            //One sentence, for the admin list. The detail is in the checks below.
            $table->string('headline')->nullable();

            /*
             * The template form as it was proposed, the named checks and what each one answered,
             * and the prose findings. Json because all three are read back whole, by the screen
             * that reopens this attempt - nothing queries inside them.
             */
            $table->json('proposal')->nullable();
            $table->json('checks')->nullable();
            $table->json('findings')->nullable();

            /*
             * Dealt with, and by whom. An attempt is resolved when a template that reads this file
             * exists - whether an admin recorded it from here or the customer re-uploaded a sheet
             * that got through on its own.
             */
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The admin list is "this business's unresolved attempts, newest first", and the
             * throttle counts "this business's attempts in the last hour". Both start here.
             */
            $table->index(['business_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_learning_attempts');
    }
};
