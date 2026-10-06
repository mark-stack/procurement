---
id: TASK-004
title: 'Finish the change log lockdown: production grant and retention'
status: Done
assignee: []
created_date: '2026-10-01 01:55'
updated_date: '2026-10-06 12:40'
labels:
  - iso-9001
  - clause-7.5.3.2
  - records
  - ops
dependencies: []
priority: high
type: chore
ordinal: 1000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
docs/records-retention.md is the policy: the grant, the four retention periods, and how a disposal is carried out. config/retention.php is the half of it two commands read, deliberately not env-driven - a retention period is a decision somebody wrote down, and an env var is a line that can be changed on a server at 2am, after which the documented policy and the enforced one quietly differ.

The grant is table by table, which is the ugly way, and it is the only way. Two tidier arrangements were rehearsed against MySQL 8.0.40 first and both failed: a database-wide GRANT followed by a table-level REVOKE is answered with ERROR 1147, and `partial_revokes` - the 8.0.16 feature that exists for exactly this - does not help, because partial revokes work at schema level and cannot carve one table out of a database-wide grant. So UPDATE and DELETE are never granted at a level covering record_changes or record_dispositions, which means one GRANT per table. `records:check-grant --sql` prints them from information_schema rather than this document carrying a copy that goes stale the next time a migration adds a table.

`records:check-grant` probes rather than parses SHOW GRANTS: four statements per table, each matching no rows, inside a rolled-back transaction. MySQL checks privileges when it opens the table, so a refusal is a real refusal and nothing is touched. Privileges arrive database-wide, per table, through a role or as a partial revoke, and a parser that reads any of those wrong answers the wrong question confidently. It runs daily from the scheduler and as a deploy step, and a failure is a Log::critical as well as a non-zero exit, because the run that matters is the one at three in the morning. On SQLite it reports success and says why. Also rehearsed before shipping: deleting a user still works under the grant, even though `record_changes.user_id` is nullOnDelete and MySQL therefore writes NULL over a table the application has no UPDATE on - referential actions are not checked against the privileges of the account that caused them.

Retention is seven years for the three that are records - the longest of the periods that bind an Australian fabricator - and seven days for Telescope, which is the one thing on the list that is not a record of anything. Telescope is off in production (config/telescope.php), and while it is on, telescope:prune runs nightly against the same number, guarded on the same switch. That is the only automatic disposal here and the exception that proves the rule.

Nothing on a schedule deletes a record. `records:dispose` with no arguments reviews what is past each period and deletes nothing, which is the state it is in on every day but the handful where somebody has decided to act. Named with a class it demands --authorised-by and --reason, neither prompted for, and writes a record_dispositions row: the class, the rule as it stood on the day (copied, not referenced), how many were in scope, how many actually went, the reason and the person - matched to an account where the name is an email we know, kept as a string either way, because a quality manager with no login here is a perfectly good authoriser. That table is append-only in the same three places record_changes is, and under the same grant.

Of the four classes it carries out one. Certificate files: the file goes, the row stays with file_disposed_at stamped on it, the change log records that too, and a download answers 410 with the date rather than the 404 a lost file would give - a disposal has to be distinguishable from a loss. The change log it cannot touch by design, so it records the authorisation with disposed at 0 and prints the DELETE for whoever holds the database. Telescope and done projects it refuses, with the reason: the first is automatic, and a bulk delete of customers' projects is one bad argument away from being the worst thing in this application.

1104 tests pass, PHPStan unchanged at 22.

Still open: AC #1 is an act on the production MySQL server, not a file. Everything needed to carry it out and to prove it afterwards is here - the statements, the check, the deploy step and the daily alarm - but the grant itself has not been applied to any server by this change.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The production database user holds INSERT and SELECT on record_changes and no UPDATE or DELETE
- [x] #2 That grant is written down somewhere the next deploy or restore will not quietly undo it
- [x] #3 A retention period is decided and documented for record_changes, certificate files, Telescope entries and archived projects
- [x] #4 Disposition is a deliberate recorded act, not a cron job nobody reads
- [x] #5 Telescope either has a retention rule or is confirmed off in production, and the config says which
<!-- AC:END -->
