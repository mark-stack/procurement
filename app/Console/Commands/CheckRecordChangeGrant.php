<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckRecordChangeGrant extends Command
{
    protected $signature = 'records:check-grant
        {--sql : print the statements that set the grant up for this schema, instead of checking it}
        {--user= : the MySQL account the printed statements name, as user@host}';

    protected $description = 'Check that the database user can insert into and read the append-only tables, and cannot change or delete anything in them';

    /**
     * The half of the change log's lockdown that no file in this repository can enforce.
     *
     * record_changes is append-only in three places - no updated_at column, a model that throws on
     * update() and delete(), and no route that writes one by hand - and all three live inside the
     * application. A grant is what makes it true from the outside: the application's database user
     * holds INSERT and SELECT on these tables and nothing else, so a defect, an injected statement
     * or a php artisan tinker session on a bad afternoon cannot rewrite what happened.
     *
     * The grant is the half that gets lost. It is typed once by whoever set the server up, it is in
     * nobody's deploy script because deploys do not touch privileges, and the day it goes is the day
     * the database is restored onto a new server, or moved to a managed instance that hands you a
     * user with ALL PRIVILEGES, or the day somebody runs a migration as root and recreates the
     * account afterwards. Nothing breaks when it goes. The application carries on exactly as before,
     * append-only in intent and not in fact, and the next person to find out is an auditor.
     *
     * So it is checked, daily (routes/console.php) and as a deploy step (README), and a failure is
     * loud. docs/records-retention.md has the statements to put it back.
     */
    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();

        if ($this->option('sql')) {
            if ($driver !== 'mysql') {
                $this->components->error("Connection driver is {$driver}, not mysql - there are no grant statements to print.");

                return self::FAILURE;
            }

            return $this->printStatements();
        }

        if ($driver !== 'mysql') {
            /*
             * Nothing to check and nothing to worry about. Production is MySQL 8 (README), and the
             * other connection this application ever has is SQLite - the test suite's in-memory
             * database and a developer's file - where there is one user, it owns the file, and
             * privileges are not a thing that exists. Reporting success rather than skipping, so a
             * deploy step can run this unconditionally.
             */
            $this->components->info("Connection driver is {$driver}, not mysql - table grants are a MySQL arrangement and there is nothing to check here.");

            return self::SUCCESS;
        }

        $failures = [];
        $rows = [];

        foreach (self::PROBES as $table => $probes) {
            foreach ($probes as $privilege => $probe) {
                $held = $this->userHolds($probe);
                $wanted = in_array($privilege, ['SELECT', 'INSERT'], true);

                $rows[] = [
                    $table,
                    $privilege,
                    $wanted ? 'yes' : 'no',
                    $held ? 'yes' : 'no',
                    $held === $wanted ? 'ok' : 'WRONG',
                ];

                if ($held !== $wanted) {
                    $failures[] = $wanted
                        ? "{$table}: the application cannot {$privilege}, which is what it needs to do"
                        : "{$table}: the application can {$privilege}, which is what the grant exists to stop";
                }
            }
        }

        $this->table(['Table', 'Privilege', 'Wanted', 'Held', ''], $rows);

        if ($failures === []) {
            $this->components->info('The append-only tables are insert-and-read only for this user.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->components->error($failure);
        }

        /*
         * The grant as it actually stands, printed rather than described. Whoever reads this output
         * is about to type a GRANT statement, and the one thing they need in front of them is what
         * the user holds now - particularly whether the privilege arrives through the database-wide
         * grant or a role, which is the difference between a REVOKE that works and the "there is no
         * such grant defined" that MySQL answers with when partial revokes are off.
         */
        $this->newLine();
        $this->line('SHOW GRANTS FOR CURRENT_USER:');

        foreach (DB::select('show grants for current_user()') as $grant) {
            $this->line('  '.implode('', array_values((array) $grant)));
        }

        $this->newLine();
        $this->line('The statements that put this back are in docs/records-retention.md.');

        /*
         * Critical rather than warning, and logged as well as printed, because the run that matters
         * is the scheduled one at three in the morning that nobody is watching. An append-only table
         * that is quietly writable is not a degraded feature, it is a record that has stopped being
         * evidence.
         */
        Log::critical('The append-only table grant is wrong on this database.', [
            'failures' => $failures,
            'database' => DB::connection()->getDatabaseName(),
        ]);

        return self::FAILURE;
    }

    /**
     * The grant for this schema as it stands today, generated rather than written down.
     *
     * MySQL privileges are hierarchical and cannot be subtracted. `GRANT ... ON procurement.*`
     * followed by a table-level `REVOKE` is answered with "there is no such grant defined", and
     * partial revokes - the 8.0.16 feature that exists for exactly this - work at schema level
     * only, so they cannot carve one table out of a database-wide grant either. Both were tried
     * against MySQL 8.0.40 before this was written; see docs/records-retention.md.
     *
     * What is left is to never grant UPDATE and DELETE at a level that covers the append-only
     * tables: database-wide for everything they need, and table by table for the two they must not
     * have. Which means the statements go stale the moment a migration adds a table - the new table
     * has no UPDATE grant and the first save to it fails - so they are printed from
     * information_schema rather than kept in a document that somebody has to remember to edit.
     *
     * `php artisan records:check-grant --sql` after any deploy that migrated a new table in.
     */
    private function printStatements(): int
    {
        $database = DB::connection()->getDatabaseName();

        $account = $this->option('user') ?: config('database.connections.mysql.username').'@%';
        [$user, $host] = array_pad(explode('@', (string) $account, 2), 2, '%');
        $account = "'{$user}'@'{$host}'";

        $this->line("-- {$database}, as it stands on ".now()->toDateString().'. Regenerate after any migration that adds a table.');
        $this->newLine();

        $this->line("GRANT SELECT, INSERT ON `{$database}`.* TO {$account};");
        $this->line('-- DDL, because the application runs its own migrations');
        $this->line("GRANT CREATE, ALTER, DROP, INDEX, REFERENCES ON `{$database}`.* TO {$account};");
        $this->newLine();

        $locked = array_keys(self::PROBES);

        $this->line('-- UPDATE and DELETE, table by table, holding back: '.implode(', ', $locked));

        $tables = DB::select(
            'select table_name as name from information_schema.tables
             where table_schema = ? and table_type = ? order by table_name',
            [$database, 'BASE TABLE'],
        );

        foreach ($tables as $table) {
            if (in_array($table->name, $locked, true)) {
                continue;
            }

            $this->line("GRANT UPDATE, DELETE ON `{$database}`.`{$table->name}` TO {$account};");
        }

        $this->newLine();
        $this->line('FLUSH PRIVILEGES;');
        $this->newLine();
        $this->line('-- Then: php artisan records:check-grant');

        return self::SUCCESS;
    }

    /**
     * One statement per privilege per table, each one affecting no rows.
     *
     * A probe rather than a read of SHOW GRANTS, because the question is "can this user change this
     * table" and the grant text is only evidence about it. Privileges arrive database-wide, per
     * table, through a role, or as a partial revoke carved out of a wider grant, and a parser that
     * gets any of those four wrong answers the wrong question confidently. MySQL checks privileges
     * when it opens the table, before it looks at a single row, so `where 1 = 0` is refused exactly
     * as a real statement would be and touches nothing on the way.
     *
     * The INSERT probe is an INSERT ... SELECT with a false condition for the same reason: a real
     * insert rolled back would still burn an auto-increment id, and a gap in the ids of an
     * append-only log is a thing somebody would one day have to explain.
     *
     * @var array<string, array<string, string>>
     */
    private const PROBES = [
        'record_changes' => [
            'SELECT' => 'select `id` from `record_changes` where 1 = 0',
            'INSERT' => "insert into `record_changes` (`record_type`, `record_id`, `event`, `changes`, `created_at`) select 'probe', 0, 'probe', '{}', now() from dual where 1 = 0",
            'UPDATE' => 'update `record_changes` set `record_id` = `record_id` where 1 = 0',
            'DELETE' => 'delete from `record_changes` where 1 = 0',
        ],
        /*
         * The disposal log, held to the same rule. It is the account of what was destroyed and by
         * whose authority, so a user that can edit it can rewrite the only thing standing between a
         * controlled disposition and somebody quietly deleting seven years of history.
         */
        'record_dispositions' => [
            'SELECT' => 'select `id` from `record_dispositions` where 1 = 0',
            'INSERT' => "insert into `record_dispositions` (`record_class`, `retain_days`, `cutoff`, `eligible`, `disposed`, `method`, `authorised_by`, `reason`, `created_at`) select 'probe', 0, now(), 0, 0, 'probe', 'probe', 'probe', now() from dual where 1 = 0",
            'UPDATE' => 'update `record_dispositions` set `eligible` = `eligible` where 1 = 0',
            'DELETE' => 'delete from `record_dispositions` where 1 = 0',
        ],
    ];

    /**
     * Run one probe and say whether the server allowed it.
     *
     * Inside a transaction that is always rolled back. The statements match no rows, so there is
     * nothing to undo and this is belt and braces - but the belt is cheap and the thing being
     * probed is the audit trail.
     *
     * Only "command denied" - MySQL 1142, the privilege refusal - comes back as false. Anything
     * else is rethrown: a missing table, a syntax error or a dropped connection is a real problem,
     * and reading it as "the grant is correct" would turn this check into the thing it exists to
     * catch.
     */
    private function userHolds(string $probe): bool
    {
        DB::beginTransaction();

        try {
            DB::statement($probe);

            return true;
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'command denied')) {
                throw $exception;
            }

            return false;
        } finally {
            DB::rollBack();
        }
    }
}
