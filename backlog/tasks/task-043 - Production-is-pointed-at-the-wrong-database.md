---
id: TASK-043
title: Production is pointed at the wrong database
status: To Do
assignee: []
created_date: '2026-10-08 10:24'
labels:
  - ops
dependencies: []
priority: high
ordinal: 76500
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The site's DB_DATABASE is 'procurement', an empty but fully migrated schema - 0 products, 0 users, 0 projects, 0 uploads. The live data is in 'steelnesting' (~1105 products), on the same MySQL server. Found while chasing a deploy failure on 2026-10-08: catalogue:sync planned 1117 creates against 0 unchanged and 0 deletes, which only happens when the existing platform set is empty.

Nobody has used the site since the pointer went wrong - 'procurement' has no users and no projects - so there is no split-brain data to merge, just a pointer to correct and a reason to find.

Steps, in order. Nothing here is safe to reorder.

1. Back up 'steelnesting' before touching anything
2. Establish migration drift: committed migrations against steelnesting.migrations, and anything in that table this branch does not have
3. Find out WHY DB_DATABASE is wrong, before changing it. If it is held in Forge's environment panel or written by the deploy script, editing .env by hand will not stick
4. Point the environment at 'steelnesting', then php artisan config:cache - config is cached on deploy, so an uncached change reads as no change
5. php artisan migrate --force against it
6. php artisan catalogue:sync with NO --apply, and read the plan. ~1105 rows against the committed 1117
7. Dispose of the empty 'procurement' schema deliberately once this is confirmed. Leaving a plausible-looking migrated schema beside the real one is how the next person loses another evening

Blocks TASK-042: --apply must not go into the deploy script until the environment is right, or it fills the empty database.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 steelnesting is backed up before any change
- [ ] #2 The environment points at the live database, and survives a deploy
- [ ] #3 The cause of the wrong pointer is identified, not just the symptom
- [ ] #4 catalogue:sync reports a sane plan against the live catalogue
- [ ] #5 The empty procurement schema is disposed of, or documented as deliberately kept
<!-- AC:END -->
