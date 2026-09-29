<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Importing was driven by config/TableTemplates.php, and this table only documented it. The
     * two held the same information in two notations - a cell reference is an offset once you
     * know where the heading row starts - so recording a customer's spreadsheet did nothing until
     * somebody wrote the same table out again in PHP and deployed it.
     *
     * The table now carries the whole detection spec. What it was missing is the anchor: the cell
     * the heading row's first label sits in, in the sample the record was read off. With that,
     * every offset the importer wants is arithmetic on the cell references an admin already types.
     *
     * Everything added here is nullable because rows recorded before it exist and describe nothing
     * detectable. They are deactivated below rather than guessed at.
     */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            //The two bolt summaries have no description column - they build one out of other cells
            $table->string('first_description_cell')->nullable()->change();
            $table->string('first_sub_qty_cell')->nullable()->change();

            //A screenshot is evidence of where the record came from, not something to detect with
            $table->longText('screenshot')->nullable()->change();

            //The anchor, and the labels that find it in a sheet
            $table->string('heading_cell')->nullable()->after('source');
            $table->json('expected_heading_labels')->nullable()->after('heading_cell');

            //Read by the importer, and never on the form
            $table->string('first_grade_cell')->nullable()->after('first_material_cell');
            $table->string('first_surface_cell')->nullable()->after('first_grade_cell');

            //Which column the skip and end-of-table rules read, and what they look for in it
            $table->string('skip_or_finish_check_cell')->nullable()->after('first_sub_qty_cell');
            $table->string('should_skip_row')->nullable()->after('skip_or_finish_check_cell');
            $table->string('is_last_data_row')->nullable()->after('should_skip_row');

            //A description assembled out of several cells, for tables with no description column
            $table->string('compound_description_prefix')->nullable()->after('is_last_data_row');
            $table->string('compound_description_suffix')->nullable()->after('compound_description_prefix');
            $table->json('compound_description_cells')->nullable()->after('compound_description_suffix');

            /*
             * One cell says both things: for COLUMN it is the mark's cell in the first data row,
             * for FIXED it is the one cell above the table every row's mark is read from.
             */
            $table->string('assembly_mark_rule')->default('NONE')->after('compound_description_cells');
            $table->string('assembly_mark_cell')->nullable()->after('assembly_mark_rule');

            //TemplateEnums. Recorded and reported, not read by the importer.
            $table->string('type')->nullable()->after('assembly_mark_cell');

            //Where the report format is documented, if it is documented anywhere
            $table->string('web_source')->nullable()->after('type');
        });

        /*
         * config_label named an entry in a config file that this change deletes. Dropped in its
         * own statement: SQLite rebuilds the table for a drop, and doing that in the same closure
         * as the ->change() calls above loses them.
         */
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('config_label');
        });

        /*
         * Every row that existed before this migration holds five cell references and no anchor,
         * so it cannot detect anything. Active now means "real uploads are matched against this",
         * and leaving them active would claim they are.
         */
        DB::table('templates')->update(['active' => false]);

        /*
         * Nothing is written in their place. config/TableTemplates.php matched its four Tekla
         * entries against every business, and copying them onto every business would have kept
         * that going - but they are one customer's export settings, and a template that matches a
         * heading row it was not calibrated against reads the columns beside the ones it wants.
         * A business imports with the templates an admin records for it, and with nothing else.
         */
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn([
                'heading_cell',
                'expected_heading_labels',
                'first_grade_cell',
                'first_surface_cell',
                'skip_or_finish_check_cell',
                'should_skip_row',
                'is_last_data_row',
                'compound_description_prefix',
                'compound_description_suffix',
                'compound_description_cells',
                'assembly_mark_rule',
                'assembly_mark_cell',
                'type',
                'web_source',
            ]);

            $table->string('config_label')->nullable()->after('source');
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->string('first_description_cell')->nullable(false)->change();
            $table->string('first_sub_qty_cell')->nullable(false)->change();
            $table->longText('screenshot')->nullable(false)->change();
        });
    }
};
