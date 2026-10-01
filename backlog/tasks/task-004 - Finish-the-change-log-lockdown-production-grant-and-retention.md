---
id: TASK-004
title: 'Finish the change log lockdown: production grant and retention'
status: To Do
assignee: []
created_date: '2026-10-01 01:55'
labels:
  - iso-9001
  - clause-7.5.3.2
  - records
  - ops
dependencies: []
priority: high
type: chore
ordinal: 4000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
PR #50 made record_changes append-only in the three places application code can: no updated_at column, a model that throws on update() and delete(), and no route that writes one by hand. The half that file could not enforce is the database grant, and the migration says so - the application user wants INSERT and SELECT on that table and nothing else.

Separately, no retention or disposition rule exists anywhere in this application: not for the change log, not for material certificate files, not for Telescope entries, not for archived projects. ISO 9001 7.5.3.2 requires both retention and disposition to be controlled.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The production database user holds INSERT and SELECT on record_changes and no UPDATE or DELETE
- [ ] #2 That grant is written down somewhere the next deploy or restore will not quietly undo it
- [ ] #3 A retention period is decided and documented for record_changes, certificate files, Telescope entries and archived projects
- [ ] #4 Disposition is a deliberate recorded act, not a cron job nobody reads
- [ ] #5 Telescope either has a retention rule or is confirmed off in production, and the config says which
<!-- AC:END -->
