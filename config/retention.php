<?php

/*
|--------------------------------------------------------------------------
| Retention and disposition
|--------------------------------------------------------------------------
|
| How long this application keeps the things it keeps, and who is allowed to throw them away.
|
| ISO 9001 7.5.3.2 asks for both halves of this, and until now the application had neither. The
| change log was append-only and would have grown for ever; certificate files were kept
| indefinitely because nothing ever said to stop; Telescope wrote entries into the application's own
| database with no rule at all; and a project marked done sat there as long as the account did.
| "Indefinitely, because nobody decided" is not a retention policy - it is the absence of one, and
| it fails the clause in both directions, since holding a customer's records for ever is as
| uncontrolled as deleting them on a whim.
|
| Seven years, for the three that are records. The longest of the periods that actually bind an
| Australian fabricator: the Corporations Act 2001 s286 keeps financial records seven years, the ATO
| asks five, and the construction-category record requirements that sit behind AS/NZS 5131 run to
| the design life of the structure for the certificates themselves - which is why the certificate
| *row* below is kept for ever and only the file is disposed of. One period across the three keeps
| the policy explainable, and a date one of them can be read against is a date all three can.
|
| Deliberately NOT env-driven, unlike nearly everything else in config/. A retention period is a
| decision the organisation made and wrote down; an env var is a line somebody can change on a
| server at 2am, after which the documented policy and the enforced one quietly differ and nothing
| says which is which. Changing one of these numbers is a commit, with a reason in the message.
|
| What reads this:
|   - `php artisan records:dispose` with no arguments, which lists what is now eligible under each
|     rule and disposes of nothing
|   - `php artisan records:dispose <class> --authorised-by= --reason=`, which disposes of one class
|     and writes the act to record_dispositions
|   - routes/console.php, for the Telescope prune, which is the one automatic disposal here and the
|     one thing below that is not a record
|
| See docs/records-retention.md for the whole policy, including the production grant that stops the
| application deleting the change log at all.
|
*/

return [

    /*
     * Seven years in days.
     *
     * 7 x 365 plus two leap days. A retention period is a floor rather than a target, so it rounds
     * up: 2555 days would fall a day or two short of seven calendar years for anything spanning two
     * leap years, and "we destroyed it six years and 364 days later" is the kind of answer that
     * turns a tidy audit into a finding.
     */
    'seven_years_in_days' => 2557,

    'classes' => [

        'change-log' => [
            'label' => 'Change log entries',
            'what' => 'record_changes rows - what moved on an order, a piece, a certificate, a product or a template, and who moved it',
            'retain_days' => 2557,
            'basis' => 'created_at, the date of the event',
            /*
             * Not the application's to delete, by construction. The model throws on delete() and in
             * production the database user holds INSERT and SELECT on this table and nothing else -
             * see docs/records-retention.md and `php artisan records:check-grant`. So the command
             * records the authorisation and hands the statement to whoever holds the database, which
             * is the arrangement the whole table was built around rather than a limitation of it.
             */
            'disposed_by' => 'dba',
        ],

        'certificates' => [
            'label' => 'Material certificate files',
            'what' => 'the PDF a merchant sent. The row naming it - heat, filename, merchant, who attached it, when - is kept for ever',
            'retain_days' => 2557,
            'basis' => 'created_at, the date the file was attached',
            'disposed_by' => 'application',
        ],

        'telescope' => [
            'label' => 'Telescope entries',
            'what' => 'request, query, exception and job entries in telescope_entries',
            /*
             * Seven days, not seven years, because these are the one thing on this list that is not
             * a record of anything. They are debug output: what a request did, with its parameters,
             * on the afternoon somebody turned Telescope on to chase a failure down. Keeping them
             * would mean keeping a second copy of customers' data in a table no quality process
             * reads and no retention argument justifies.
             *
             * This is also the only automatic disposal here, and that is the point rather than an
             * exception to it - nothing automatic may delete a record, and an entry that is not a
             * record is not covered by that rule. `telescope:prune` runs nightly from
             * routes/console.php.
             */
            'retain_days' => 7,
            'basis' => 'created_at, the date of the entry',
            'disposed_by' => 'automatic',
        ],

        'done-projects' => [
            'label' => 'Projects marked done',
            'what' => 'a finished project and everything under it - batches, pieces, orders, bars, offcuts and the certificates against them',
            'retain_days' => 2557,
            /*
             * updated_at, because there is no done_at. The projects table carries a boolean and
             * nothing else - see the 2026_10_04 rename - so the closest thing to a closing date is
             * the last time anything moved on it, which for a project nobody has touched in seven
             * years is near enough. The exact date it was marked done is in the change log, which is
             * where a disposal anybody is likely to argue about should be read from.
             */
            'basis' => 'updated_at, the last time anything moved on it',
            /*
             * Reported, never performed. Deleting a project takes batches, pieces, orders, bars,
             * offcuts and certificates with it, and a command that does that in bulk across every
             * customer is one bad --class argument away from being the worst thing in this
             * application. The owner deletes their own project from the projects screen, one at a
             * time, and the change log records it.
             */
            'disposed_by' => 'owner',
        ],

    ],

];
