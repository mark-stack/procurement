<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What was thrown away, by whose authority, and under which rule.
     *
     * ISO 9001 7.5.3.2 asks for two things about retained documented information, and this
     * application had neither written down anywhere: how long it is kept, and how it is disposed of.
     * The first is now config/retention.php. This table is the second half - not the rule, but the
     * account of each time somebody applied it.
     *
     * The requirement is specifically that disposition is *controlled*. A nightly job quietly
     * deleting everything older than seven years satisfies a retention period and fails that, because
     * nobody decided anything on the night it ran and nothing afterwards says what went. So nothing
     * on a schedule deletes a record in this application. A person runs `records:dispose`, names
     * themselves, says why, and one row lands here saying what was in scope, how much of it actually
     * went, and which of the two hands did it - the application's, or the database administrator's,
     * for the change log the application deliberately cannot delete.
     *
     * Append-only in the same three places record_changes is, and for the same reason: no updated_at,
     * a model that refuses update() and delete(), and nothing in the application that writes one
     * except the command. A record of a disposal that can be edited afterwards is not evidence of
     * anything. The production grant in docs/records-retention.md covers this table alongside
     * record_changes.
     */
    public function up(): void
    {
        Schema::create('record_dispositions', function (Blueprint $table) {
            $table->id();

            //created_at alone - a disposal does not get a second version. Indexed because the only
            //read of this table is "what have we disposed of, most recent first"
            $table->timestamp('created_at')->nullable()->index();

            /*
             * Which class of record, by the key it has in config/retention.php. A key rather than a
             * table name: "certificates" is a rule covering files on a disk and the rows pointing at
             * them, and "done-projects" covers a project and everything hanging off it.
             */
            $table->string('record_class', 50);

            /*
             * The rule as it stood on the day, copied rather than referenced.
             *
             * config/retention.php is a file that can be edited, and a disposal has to stay readable
             * against the rule that authorised it rather than against whatever the rule says now - the
             * question an auditor asks is "was this disposed of too early", and that cannot be
             * answered from a config file that has since moved.
             */
            $table->unsignedInteger('retain_days');
            $table->timestamp('cutoff');

            //How many were in scope, and how many actually went. The two differ whenever the act was
            //handed to somebody else to carry out, where this row is the authorisation and not the deed
            $table->unsignedInteger('eligible');
            $table->unsignedInteger('disposed');

            //application | handed-to-dba. See App\Models\RecordDisposition
            $table->string('method', 40);

            /*
             * Who authorised it, twice over.
             *
             * The foreign key for the account, nullOnDelete for the reason every other user_id in this
             * schema is nullable: losing an account must never take the record of what that account
             * did with it. And the name as it was typed, which is the half that survives - the command
             * takes it as a string precisely so that a disposal authorised by somebody with no login
             * here, a quality manager signing off a purge, still names a person.
             */
            $table->foreignId('authorised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('authorised_by', 191);

            //Why, now. Required by the command, because "the retention period expired" is the rule and
            //not the reason, and a disposal nobody can explain the timing of is the one worth asking about
            $table->text('reason');

            $table->index(['record_class', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_dispositions');
    }
};
