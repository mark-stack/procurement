---
id: TASK-010
title: CI that enforces the test suite and the PHPStan baseline
status: To Do
assignee: []
created_date: '2026-10-01 01:56'
labels:
  - ci
  - quality
  - clause-8.3
dependencies: []
priority: high
type: chore
ordinal: 10000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
There is no CI in this repository - no .github directory, no pipeline config of any kind. 819 tests pass and the PHPStan baseline is 23 errors, but nothing runs either on push, so "it passed" is a statement about one laptop.

This is the cheapest gap on the whole list to close, and it is what makes every other verification claim checkable by somebody other than the author. The accepted PHPStan count wants its rationale written down too: an accepted-defect list with no reasoning reads to an auditor as an uncontrolled concession.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The Pest suite runs on push and on pull request, and a failure blocks the merge
- [ ] #2 PHPStan runs with --memory-limit=1G and fails only on a rise above the recorded baseline
- [ ] #3 The baseline number lives in the repo beside a note of why those errors are accepted
- [ ] #4 The Pint decision is written down: installed, deliberately not run, and why (it rewrites the house comment style)
<!-- AC:END -->
