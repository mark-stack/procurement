---
id: TASK-008
title: Master catalogue review record and measurement inputs
status: To Do
assignee: []
created_date: '2026-10-01 01:56'
labels:
  - iso-9001
  - clause-7.1.5
  - catalogue
  - minor
dependencies: []
priority: medium
type: task
ordinal: 8000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
default_kg_per_m is 10.0 and is the silent fallback for any product with no mass recorded, feeding both cost and tonnage. The catalogue has known-bad rows: a grade sitting in a material column (SS316), three RHS products with no description at all. products.certificates is tri-state, which is honest - null means nobody has said - but nothing audits how many rows are still null. And nothing records when the catalogue was last reviewed, or by whom.

ISO 9001 7.1.5 is about the suitability of the resources used for monitoring and measurement. A mass per metre that decides a tonne price is one of those.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A record of when the catalogue was last reviewed and by whom
- [ ] #2 A report of rows that cannot be trusted: no mass per metre, an unanswered certificates flag, a spec column holding the wrong kind of value
- [ ] #3 The 10.0 fallback is either removed, or made visible wherever a figure derived from it is shown
- [ ] #4 The known-bad rows are corrected, or deliberately accepted with the reason recorded
<!-- AC:END -->
