# Records: retention and disposition

What this application keeps, how long for, and who may throw it away.

ISO 9001 7.5.3.2 asks that retained documented information is protected from unintended alteration,
and that its retention **and its disposition** are controlled. The change log answers the first half
([PR #50](https://github.com/mark-stack/procurement/pull/50): no `updated_at`, a model that throws,
no route that writes one by hand). This file is the rest of it.

Three things are described here:

| | Where it lives | What checks it |
| --- | --- | --- |
| The append-only guarantee | the application | `RecordChangeTest`, `RetentionTest` |
| The retention periods | `config/retention.php` | `php artisan records:dispose` with no arguments |
| Each act of disposition | the `record_dispositions` table | it is the record |

## What makes the record protected

`record_changes` and `record_dispositions` are append-only in three places, all of them inside the
application:

- no `updated_at` column - there is no second version of an event
- `App\Models\RecordChange` and `App\Models\RecordDisposition` throw on `update()` and `delete()`
- nothing writes one by hand - the trait and `records:dispose` are the only writers

That is the whole of it, and the limit is worth stating plainly rather than leaving for somebody to
discover later. All three live in PHP, so all three are undone by one
`DB::table('record_changes')->update(...)` that gets past review, or one `php artisan tinker` session
on a bad afternoon. What they protect against is the honest mistake - a future screen calling
`update()` on a log row because every other model here allows it - and not deliberate rewriting by
anybody who can deploy.

### Why there is no database-level grant

There was one in this repository, and it was dropped on 2026-10-08. It had never been applied to a
server, so nothing about production changed when it went.

The arrangement was: the application's MySQL user holds INSERT and SELECT on these two tables and no
UPDATE or DELETE, so MySQL refuses the statement before it reads a row. What it cost was the problem.
MySQL privileges cannot be subtracted - `GRANT ... ON procurement.*` followed by a table-level
`REVOKE` is answered with ERROR 1147, and `partial_revokes` operates at schema level, so neither can
carve one table out of a database-wide grant. Both were rehearsed against MySQL 8.0.40. What is left
is granting UPDATE and DELETE **one table at a time** for every table but these two: a generated list
that goes stale the moment a migration adds a table, a new table that fails on its first write until
somebody regenerates it, and a deploy step plus a daily alarm to notice when the whole thing has
quietly gone.

On a single-server Forge deployment, where the same person holds the deploy and the database, that
buys very little. Anybody who can change the grant can change the code the grant was protecting the
log from.

It is worth putting back if this ever runs somewhere the application's database user genuinely is not
the operator's - a managed instance, or a separate database administrator.
`git show b94db34:app/Console/Commands/CheckRecordChangeGrant.php` has both the generator for the
statements and the check that probed them.

### What this does not protect against

Somebody with database access, which now includes the application's own user. A disposal carried out
that way is supposed to leave a `record_dispositions` row behind it, and that row is written before
the statement is handed over, by `records:dispose` - see below.

## The retention schedule

The periods live in `config/retention.php`, as data rather than prose, because two commands read
them. They are deliberately **not** env-driven: a retention period is a decision the organisation
made and wrote down, and an env var is a line somebody can change on a server at 2am, after which
the documented policy and the enforced one quietly differ. Changing one is a commit, with a reason
in the message.

| Class | What | Kept for | Measured from | Disposed of by |
| --- | --- | --- | --- | --- |
| `change-log` | `record_changes` rows | 7 years | the date of the event | the database administrator, authorised here |
| `certificates` | the merchant's PDF. The row naming it is kept for ever | 7 years | the date the file was attached | `records:dispose certificates` |
| `telescope` | `telescope_entries` | 7 days | the date of the entry | `telescope:prune`, nightly and automatic |
| `done-projects` | a project marked done, and everything under it | 7 years | the last time anything moved on it | its owner, from the projects screen, one at a time |

### Why seven years

It is the longest of the periods that actually bind an Australian fabricator. The Corporations Act
2001 s286 keeps financial records for seven years; the ATO asks five; the construction-category
record requirements behind AS/NZS 5131 run to the design life of the structure for the mill
certificates themselves - which is why the certificate **row** is kept indefinitely and only the
file is ever disposed of. One period across the three that are records keeps the policy explainable,
and a date any one of them can be read against is a date all three can.

Seven days for Telescope, because its entries are the only thing on that list that is not a record
of anything. They are debug output: what a request did, with its parameters, on the afternoon
somebody turned the recorder on. Keeping them would mean keeping a second copy of customers' data in
a table no quality process reads and no retention argument justifies.

### Telescope in production

Off. `config/telescope.php` reads `env('TELESCOPE_ENABLED', env('APP_ENV') !== 'production')`, so a
production deployment records nothing unless somebody sets `TELESCOPE_ENABLED=true` deliberately,
for an afternoon of chasing something down. `.env.example` says so at the commented-out line.

While it is on, the nightly `telescope:prune` keeps a week. The schedule entry is guarded on the
same switch, so an installation that has never enabled Telescope is not pruning a table that was
never created.

### What is not on this list

- **`template_learning_attempts` samples** - a customer's spreadsheet kept against a failed
  learning attempt, deleted after `TEMPLATE_LEARNING_SAMPLE_RETENTION_DAYS` (default 30) by
  `templates:prune-samples`. Already had a rule before this document existed, and it is in
  `config/templates.php` with its reasoning. The row survives the file, the same way a certificate's
  does
- **`notification_deliveries` and `notification_logs`** - an operational log of what was sent and
  what was suppressed, not a quality record. No period yet
- **Laravel's own `failed_jobs`, `sessions`, `cache`** - infrastructure tables, no retention
  argument either way

## Disposition

Disposition is a **deliberate recorded act**. Nothing on a schedule deletes a record in this
application.

The obvious implementation of a retention policy is a nightly job that deletes everything past the
period. It satisfies the retention half of 7.5.3.2 and fails the disposition half completely: nobody
decides anything on the night it runs, nothing afterwards says what went, and the first time anybody
looks closely is when it has been quietly destroying something it should not have been for two
years. `telescope:prune` is the single exception, and only because an entry it deletes is not a
record.

### Reviewing

```
php artisan records:dispose
```

With no arguments it deletes nothing. It reads the schedule, counts what is now past each period,
and prints it. That is the state this command is in on every day but the handful where somebody has
decided to act - the review is the normal use, the disposal is the exception.

### Disposing

```
php artisan records:dispose certificates \
    --authorised-by="A. Quality Manager" \
    --reason="Seven-year period expired; reviewed against the 2019 job files"
```

Both options are required and neither is prompted for, because a prompt gets answered with whatever
makes the command run. It confirms, carries out the one thing it was asked to, and writes a
`record_dispositions` row holding the class, the rule **as it stood on the day** (copied, not
referenced - the config file can be edited, and the question afterwards is whether the disposal was
too early under the rule in force at the time), how many were in scope, how many actually went, the
reason, and the person. The person is matched to a user account where the name given is an email
this application knows, and kept as a plain string either way: a quality manager with no login here
is a perfectly good authoriser.

`record_dispositions` is append-only in the same three places `record_changes` is.

Per class:

- **`certificates`** - the application does it. The file is deleted from the private disk, the row
  is kept with `file_disposed_at` stamped on it, and the change log records that too. A download of
  a disposed certificate answers **410 Gone** with the date, rather than the 404 a disk that lost a
  file would give - a disposal has to be distinguishable from a loss
- **`change-log`** - the application does not, by design: `RecordChange` throws on `delete()`, and
  the command is not given a way around it. So it records the authorisation, with the count that was
  in scope and `disposed` left at 0, and prints the exact `DELETE` for whoever holds the database to
  run after a backup. Keeping the deed out of the application is the point - a disposal of the change
  log should be a deliberate act at the database, against a backup, and not a command anybody here
  can run twice
- **`telescope`** - refused, with the reason. Automatic, nightly, nothing to authorise
- **`done-projects`** - refused. Deleting a project takes batches, pieces, orders, bars, offcuts and
  certificates with it, and a command doing that in bulk across every customer is one bad argument
  away from being the worst thing in this application. The owner deletes their own, one at a time,
  from the projects screen, and the change log records it. The review lists which are eligible
