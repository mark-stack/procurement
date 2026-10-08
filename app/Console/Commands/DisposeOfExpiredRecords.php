<?php

namespace App\Console\Commands;

use App\Models\MaterialCertificate;
use App\Models\Project;
use App\Models\RecordChange;
use App\Models\RecordDisposition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class DisposeOfExpiredRecords extends Command
{
    protected $signature = 'records:dispose
        {class? : change-log, certificates, telescope or done-projects}
        {--authorised-by= : the name or email of the person authorising this disposal}
        {--reason= : why it is being done now}
        {--force : skip the confirmation}';

    protected $description = 'Review what has passed its retention period, and dispose of one class of it as a recorded act';

    /**
     * The disposition half of ISO 9001 7.5.3.2, as a thing a person does rather than a thing that
     * happens.
     *
     * Run with no arguments it deletes nothing. It reads config/retention.php, counts what is now
     * past each period, and prints it - which is the state this command is in on every day but the
     * handful where somebody has decided to act. That is deliberate: the obvious implementation of
     * a retention policy is a nightly job that deletes everything older than the period, and it
     * satisfies the retention half of the clause while failing the disposition half completely.
     * Nobody decides anything on the night it runs, nothing afterwards records what went, and the
     * first time anybody looks at it closely is when it has been quietly destroying something it
     * should not have been for two years.
     *
     * Named with a class it asks for a person and a reason, confirms, does the one thing, and
     * writes a record_dispositions row. Two of the four classes it will not carry out at all - the
     * change log because production does not grant the application DELETE on it, and done projects
     * because a bulk delete of customers' projects is not a thing that should exist here - and for
     * those it records the authorisation or refuses outright. See docs/records-retention.md.
     */
    public function handle(): int
    {
        $class = $this->argument('class');

        if ($class === null) {
            return $this->review();
        }

        $rule = config("retention.classes.{$class}");

        if (! is_array($rule)) {
            $this->components->error("There is no retention class called \"{$class}\".");
            $this->line('Known classes: '.implode(', ', array_keys(config('retention.classes'))));

            return self::FAILURE;
        }

        return match ($rule['disposed_by']) {
            'application' => $this->disposeHere($class, $rule),
            'dba' => $this->handToDatabaseAdministrator($class, $rule),
            default => $this->refuse($class, $rule),
        };
    }

    /**
     * What is eligible under each rule today, and nothing else.
     */
    private function review(): int
    {
        $rows = [];

        foreach (config('retention.classes') as $class => $rule) {
            $eligible = $this->eligible($class, $this->cutoff($rule));

            $rows[] = [
                $class,
                $rule['label'],
                $rule['retain_days'].' days',
                $this->cutoff($rule)->toDateString(),
                $eligible === null ? '-' : (string) $eligible,
                self::DISPOSED_BY[$rule['disposed_by']],
            ];
        }

        $this->table(
            ['Class', 'What', 'Retained', 'Cutoff', 'Eligible now', 'Disposed of by'],
            $rows,
        );

        $this->line('Nothing has been disposed of. To dispose of one class:');
        $this->line('  php artisan records:dispose <class> --authorised-by="Name" --reason="..."');
        $this->newLine();
        $this->line('The policy, and the production grant that stops this command touching the change log, are in docs/records-retention.md.');

        return self::SUCCESS;
    }

    /**
     * A class the application may carry out itself: certificate files, and nothing else today.
     *
     * @param  array<string, mixed>  $rule
     */
    private function disposeHere(string $class, array $rule): int
    {
        $authorisation = $this->authorisation();

        if ($authorisation === null) {
            return self::FAILURE;
        }

        $cutoff = $this->cutoff($rule);
        $eligible = $this->eligible($class, $cutoff);

        if ($eligible === 0) {
            $this->components->info("Nothing in \"{$class}\" is past its retention period. Nothing was disposed of, and nothing was recorded.");

            return self::SUCCESS;
        }

        $this->line("{$rule['label']}: {$eligible} past the {$rule['retain_days']}-day period, measured from {$rule['basis']} - everything before {$cutoff->toDateString()}.");

        if (! $this->option('force') && ! $this->confirm('Dispose of them? This cannot be undone.')) {
            $this->components->warn('Nothing was disposed of.');

            return self::SUCCESS;
        }

        /*
         * Matched on the class rather than trusting disposed_by, so that marking a future class
         * 'application' in the config and forgetting to write its disposal here throws an
         * UnhandledMatchError instead of quietly deleting certificate files under its name.
         */
        $disposed = match ($class) {
            'certificates' => $this->disposeOfCertificateFiles($cutoff),
            default => throw new LogicException(
                "\"{$class}\" is marked as disposed of by the application in config/retention.php, "
                .'and there is nothing here that disposes of it.',
            ),
        };

        $this->record($class, $rule, $cutoff, $eligible, $disposed, RecordDisposition::BY_APPLICATION, $authorisation);

        $this->components->info("Disposed of {$disposed} certificate file(s). The rows naming them are kept.");

        return self::SUCCESS;
    }

    /**
     * The change log: authorised here, carried out by whoever holds the database.
     *
     * This command could not delete these rows if it wanted to. RecordChange throws on delete(),
     * and production grants the application INSERT and SELECT on record_changes and nothing else -
     * which is the arrangement the table was built around rather than an obstacle to work past. So
     * what it does is the part that is actually missing in practice: it works out what is in scope,
     * records who authorised disposing of it and why, and hands over the exact statement.
     *
     * @param  array<string, mixed>  $rule
     */
    private function handToDatabaseAdministrator(string $class, array $rule): int
    {
        $authorisation = $this->authorisation();

        if ($authorisation === null) {
            return self::FAILURE;
        }

        $cutoff = $this->cutoff($rule);
        $eligible = $this->eligible($class, $cutoff);

        if ($eligible === 0) {
            $this->components->info("Nothing in \"{$class}\" is past its retention period. Nothing was recorded.");

            return self::SUCCESS;
        }

        $this->line("{$rule['label']}: {$eligible} past the {$rule['retain_days']}-day period - everything before {$cutoff->toDateString()}.");
        $this->line('This application cannot delete them, by design. Recording the authorisation instead.');

        if (! $this->option('force') && ! $this->confirm('Record this authorisation?')) {
            $this->components->warn('Nothing was recorded.');

            return self::SUCCESS;
        }

        $disposition = $this->record($class, $rule, $cutoff, $eligible, 0, RecordDisposition::BY_DBA, $authorisation);

        $this->newLine();
        $this->line("Authorisation #{$disposition->id} recorded. Hand this to whoever holds the database, after a backup:");
        $this->newLine();
        $this->line("  DELETE FROM `record_changes` WHERE `created_at` < '{$cutoff->toDateTimeString()}';");
        $this->newLine();
        $this->line('Take a backup first, and keep the deed out of here - a disposal of the change log is a deliberate act at the database, not a command anybody can run twice. See docs/records-retention.md.');

        return self::SUCCESS;
    }

    /**
     * A class nothing here disposes of, with the reason said out loud rather than a shrug.
     *
     * @param  array<string, mixed>  $rule
     */
    private function refuse(string $class, array $rule): int
    {
        $this->components->error("\"{$class}\" is not disposed of by this command.");

        $this->line(match ($rule['disposed_by']) {
            'automatic' => 'Telescope entries are debug output rather than a record, so theirs is the one '
                .'automatic disposal here: telescope:prune runs nightly from routes/console.php against the '
                .$rule['retain_days'].'-day period in config/retention.php. There is nothing to authorise.',
            default => 'A project is deleted by its owner, from the projects screen, one at a time - and the '
                .'change log records it. Deleting them in bulk would take batches, pieces, orders, bars, '
                .'offcuts and certificates with them across every customer at once, which is not a button '
                .'worth having. `records:dispose` with no arguments lists which ones are eligible.',
        });

        return self::FAILURE;
    }

    /**
     * The person authorising this, and the reason, or null where either is missing.
     *
     * Both required, and neither prompted for. A prompt would be answered with whatever gets the
     * command to run; typing them on the command line is the small deliberate act that this whole
     * arrangement is about, and it is what ends up in the row.
     *
     * @return array{name: string, user_id: int|null, reason: string}|null
     */
    private function authorisation(): ?array
    {
        $name = trim((string) $this->option('authorised-by'));
        $reason = trim((string) $this->option('reason'));

        if ($name === '' || $reason === '') {
            $this->components->error('A disposal needs --authorised-by and --reason. Both are recorded, and a disposal nobody is named against is the thing this command exists to prevent.');

            return null;
        }

        /*
         * Matched to an account where the name given is an email we know, and left as a plain string
         * otherwise. A quality manager with no login here is a perfectly good authoriser - what
         * matters is that a person is named, not that they are a user of this application.
         */
        $user = filter_var($name, FILTER_VALIDATE_EMAIL)
            ? User::query()->where('email', $name)->first()
            : null;

        return ['name' => $name, 'user_id' => $user?->id, 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function cutoff(array $rule): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays((int) $rule['retain_days']);
    }

    /**
     * How many of this class are past the cutoff, or null where the question cannot be asked here.
     */
    private function eligible(string $class, CarbonImmutable $cutoff): ?int
    {
        return match ($class) {
            'change-log' => RecordChange::query()->where('created_at', '<', $cutoff)->count(),

            //Only the ones still holding a file. A row whose file has already gone is the record,
            //and the record is kept for ever
            'certificates' => MaterialCertificate::query()
                ->whereNull('file_disposed_at')
                ->where('created_at', '<', $cutoff)
                ->count(),

            //Absent on any installation where Telescope has never been enabled, which is the
            //default in production - see config/telescope.php
            'telescope' => Schema::hasTable('telescope_entries')
                ? DB::table('telescope_entries')->where('created_at', '<', $cutoff)->count()
                : null,

            'done-projects' => Project::query()
                ->where('done', true)
                ->where('updated_at', '<', $cutoff)
                ->count(),

            default => null,
        };
    }

    /**
     * Delete the files, keep the rows.
     *
     * Chunked by id for the reason templates:prune-samples is: the whole point is not to hold every
     * expired certificate in memory at once. One at a time through the model, so each disposal is
     * also an entry in the change log against the certificate itself.
     */
    private function disposeOfCertificateFiles(CarbonImmutable $cutoff): int
    {
        $disposed = 0;

        MaterialCertificate::query()
            ->whereNull('file_disposed_at')
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($certificates) use (&$disposed) {
                foreach ($certificates as $certificate) {
                    $certificate->disposeOfFile();
                    $disposed++;
                }
            });

        return $disposed;
    }

    /**
     * The act itself, written down.
     *
     * The rule is copied into the row rather than referenced, because config/retention.php can be
     * edited and the question somebody will ask about this disposal is whether it was too early
     * under the rule that was in force on the day.
     *
     * @param  array<string, mixed>  $rule
     * @param  array{name: string, user_id: int|null, reason: string}  $authorisation
     */
    private function record(
        string $class,
        array $rule,
        CarbonImmutable $cutoff,
        int $eligible,
        int $disposed,
        string $method,
        array $authorisation,
    ): RecordDisposition {
        return RecordDisposition::create([
            'record_class' => $class,
            'retain_days' => (int) $rule['retain_days'],
            'cutoff' => $cutoff,
            'eligible' => $eligible,
            'disposed' => $disposed,
            'method' => $method,
            'authorised_by_user_id' => $authorisation['user_id'],
            'authorised_by' => $authorisation['name'],
            'reason' => $authorisation['reason'],
            'created_at' => now(),
        ]);
    }

    /**
     * @var array<string, string>
     */
    private const DISPOSED_BY = [
        'application' => 'this command',
        'dba' => 'the database administrator, authorised here',
        'automatic' => 'telescope:prune, nightly',
        'owner' => 'the project owner, one at a time',
    ];
}
