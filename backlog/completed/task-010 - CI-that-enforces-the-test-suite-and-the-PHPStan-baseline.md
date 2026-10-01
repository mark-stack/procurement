---
id: TASK-010
title: CI that enforces the test suite and the PHPStan baseline
status: Done
assignee: []
created_date: '2026-10-01 01:56'
updated_date: '2026-10-01 05:02'
labels:
  - ci
  - quality
  - clause-8.3
dependencies: []
priority: high
type: chore
ordinal: 2000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
`.github/workflows/ci.yml` runs two independent jobs on every push to main and every pull request: Pest, and PHPStan against a recorded baseline. Both run the same way locally as they do in CI, and neither needs a database - the suite is configured against in-memory SQLite in phpunit.xml.

The static-analysis gate is `scripts/phpstan-gate.php`, which reads the accepted count from the `Accepted PHPStan errors:` line in docs/quality-gates.md, so the number has one home and it sits beside the reasoning for it. It fails on a rise and only on a rise; a drop passes with a warning asking for the number to be lowered in the same change, because a baseline left above the real count is a gate that has stopped gating. Every accepted error still prints on every run. PHPStan's own baseline file was turned down for the opposite behaviour: it suppresses what it records, so the list stops being read, and reportUnmatchedIgnoredErrors then fails the build of whoever fixes one.

docs/quality-gates.md carries the rationale. Seventeen of the twenty-three errors are one Larastan limitation - the concrete model widening to Eloquent\Model, so relations read as undefined methods - and the other six are named individually with what each one is. One of those six is a latent defect rather than a tooling artefact: TestingFormatter:151 reads `->value` off `grade`, an uncast text column, and works only while the attribute still holds the enum assigned to it in the same request. It is reached only from tests/Pest.php, so it is a fixture-path defect, not a customer-facing one.

Rehearsing the workflow in a clean clone caught what a working machine hides: public/build is gitignored, every Inertia page renders through a Blade root that calls @vite, and with no manifest 116 tests fail on "Not a valid Inertia response". The test job builds the frontend before running Pest, which also means a frontend build that does not compile now fails CI.

819 tests pass, PHPStan unchanged at 23.

Still open: main has no branch protection, so nothing yet blocks a merge on a red run. That is a repository setting, not a file, and it is the remaining half of AC #1.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The Pest suite runs on push and on pull request, and a failure blocks the merge
- [x] #2 PHPStan runs with --memory-limit=1G and fails only on a rise above the recorded baseline
- [x] #3 The baseline number lives in the repo beside a note of why those errors are accepted
- [x] #4 The Pint decision is written down: installed, deliberately not run, and why (it rewrites the house comment style)
<!-- AC:END -->

## Implementation Notes
<!-- SECTION:NOTES:BEGIN -->
AC #1 is half done. The suite runs on push and on pull request; a failure does not block the merge, because `main` is unprotected and required status checks are a GitHub setting rather than anything committable. Enabling it means requiring the `Pest` and `PHPStan` contexts on main.

Verified before shipping, rather than assumed:

- the gate at, above and below baseline (exit 0, 1, 0-with-warning)
- the gate with the baseline line malformed, with the note file missing, and with PHPStan unable to start - all exit 2 rather than passing by default
- the whole test job rehearsed in a clean clone of HEAD, which is what found the Vite manifest problem
- the PHPStan job checked to pass with no .env, no APP_KEY and no built assets, so it carries none of those steps
<!-- SECTION:NOTES:END -->
