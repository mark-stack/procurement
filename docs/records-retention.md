# Records: the grant, retention and disposition

What this application keeps, how long for, who may throw it away, and the one piece of the
arrangement that lives on the database server rather than in this repository.

ISO 9001 7.5.3.2 asks that retained documented information is protected from unintended alteration,
and that its retention **and its disposition** are controlled. The change log answers the first half
([PR #50](https://github.com/mark-stack/procurement/pull/50): no `updated_at`, a model that throws,
no route that writes one by hand). This file is the rest of it.

Three things are described here, and only one of them is enforced by code alone:

| | Where it lives | What checks it |
| --- | --- | --- |
| The append-only grant | the MySQL server | `php artisan records:check-grant`, daily and on deploy |
| The retention periods | `config/retention.php` | `php artisan records:dispose` with no arguments |
| Each act of disposition | the `record_dispositions` table | it is the record |

## The production grant

The application's database user holds **INSERT and SELECT** on `record_changes` and
`record_dispositions`, and no UPDATE or DELETE on either.

Everything else about append-only lives inside the application - a model that refuses `update()` and
`delete()`, a table with no `updated_at`, no route that writes one by hand - and all of it is
undone by one `DB::table('record_changes')->update(...)` that gets past review. The grant is what
makes it true from outside: a defect, an injected statement or a `php artisan tinker` session on a
bad afternoon cannot rewrite what happened.

### Setting it

```
php artisan records:check-grant --sql --user=procurement@%
```

prints the statements for the schema as it stands, and they are run as a MySQL account that can
grant. They look like this:

```sql
GRANT SELECT, INSERT ON `procurement`.* TO 'procurement'@'%';
-- DDL, because the application runs its own migrations
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES ON `procurement`.* TO 'procurement'@'%';

-- UPDATE and DELETE, table by table, holding back: record_changes, record_dispositions
GRANT UPDATE, DELETE ON `procurement`.`bars` TO 'procurement'@'%';
GRANT UPDATE, DELETE ON `procurement`.`batches` TO 'procurement'@'%';
... one line per table ...

FLUSH PRIVILEGES;
```

**They are generated rather than written down here, and they must be regenerated after any deploy
whose migrations added a table.** A new table with no UPDATE grant fails on the first save to it -
loudly, which is the right way round, but a deploy step is cheaper than an incident. That is the
price of the arrangement below, and it is why this document does not carry a copy of the statements
that would quietly go stale against the schema.

#### Why table by table, which is the ugly way

MySQL privileges are hierarchical and cannot be subtracted. Two tidier arrangements were tried
against MySQL 8.0.40 first, and neither works:

1. `GRANT ... ON procurement.*` then `REVOKE UPDATE, DELETE ON procurement.record_changes` -
   answered with **ERROR 1147: there is no such grant defined for user ... on table
   'record_changes'**. A table-level revoke needs a table-level grant to revoke from
2. The same, with `partial_revokes = ON` - the MySQL 8.0.16 feature that exists for precisely this
   problem. Same error. Partial revokes operate at **schema** level: they can carve a database out
   of a global `ON *.*` grant, and cannot carve a table out of a database-wide one

So UPDATE and DELETE are never granted at a level that covers the two append-only tables, which
means granting them one table at a time. SELECT and INSERT stay database-wide - a new table needs
them immediately and the append-only tables are allowed both - so only half the statements need
regenerating.

DDL is deliberately left in place. `php artisan migrate --force` runs as this user, and a future
migration adding an index or a column to either table needs ALTER. DROP is left as well, because a
dropped table is not a quiet edit: the application stops working inside a request, which is the
loudest failure in this document.

#### Deleting a user still works

`record_changes.user_id` is `nullOnDelete`, so deleting an account makes MySQL write NULL over the
child rows - an UPDATE on a table this user has no UPDATE on. It was worth checking before shipping
the grant, and it is fine: referential actions are carried out by the server itself and are not
checked against the privileges of the account that caused them. Rehearsed on 8.0.40 against this
exact grant; the account deleted, the log row kept, its `user_id` now NULL. Which is the behaviour
the column was chosen for in the first place - losing an account must never take the record of what
that account did with it.

### Checking it

```
php artisan records:check-grant
```

It probes rather than parses. `SHOW GRANTS` has four ways of saying the same thing - database-wide,
per table, through a role, or as a partial revoke carved out of a wider grant - and a parser that
reads any of them wrong answers the wrong question confidently. Instead it runs one statement per
privilege per table, each matching no rows (`where 1 = 0`), inside a transaction that is rolled
back. MySQL checks privileges when it opens the table, before it looks at a row, so a refusal is a
real refusal and nothing is touched on the way.

It must be run:

- **as the last step of every deploy**, and it is in the README's production setup for that reason.
  A deploy that migrated a new table in needs `--sql` run again first
- **after any database restore, move, or managed-instance migration**, which is when the grant
  actually goes missing
- it also runs **daily** from the scheduler (`routes/console.php`). A failure is a `Log::critical`
  as well as a non-zero exit, because the run that matters is the one at three in the morning that
  nobody is watching

On SQLite - the test suite and a developer's machine - it reports success and says why. There is one
user, it owns the file, and table privileges are not a thing that exists.

### What this does not protect against

Somebody with the MySQL root password, which is as it should be: the point is that the *application*
cannot alter its own log, not that the organisation cannot run its own database. A disposal carried
out that way is supposed to leave a `record_dispositions` row behind it, and that row is written
before the statement is handed over, by `records:dispose` - see below.

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

`record_dispositions` is append-only in the same three places `record_changes` is, and under the
same grant.

Per class:

- **`certificates`** - the application does it. The file is deleted from the private disk, the row
  is kept with `file_disposed_at` stamped on it, and the change log records that too. A download of
  a disposed certificate answers **410 Gone** with the date, rather than the 404 a disk that lost a
  file would give - a disposal has to be distinguishable from a loss
- **`change-log`** - the application cannot, by design: the grant above leaves it no DELETE, and
  `RecordChange` throws anyway. So the command records the authorisation, with the count that was in
  scope and `disposed` left at 0, and prints the exact `DELETE` for whoever holds the database to run
  after a backup. Restoring the grant to run it and forgetting to take it away again is how the
  lockdown ends; use an account that already holds DELETE
- **`telescope`** - refused, with the reason. Automatic, nightly, nothing to authorise
- **`done-projects`** - refused. Deleting a project takes batches, pieces, orders, bars, offcuts and
  certificates with it, and a command doing that in bulk across every customer is one bad argument
  away from being the worst thing in this application. The owner deletes their own, one at a time,
  from the projects screen, and the change log records it. The review lists which are eligible
