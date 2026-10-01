---
id: TASK-006
title: Scrap as a record
status: To Do
assignee: []
created_date: '2026-10-01 01:55'
labels:
  - iso-9001
  - clause-8.7
  - clause-9.1
  - scrap
  - minor
dependencies: []
priority: medium
type: feature
ordinal: 47500
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
app/Models/Scrap.php is an empty class, ScrapController is seven empty stubs, and Batch::scrap() returns an empty collection with a todo on it. Scrap exists only as a per-bar number inside the nested_state JSON and as the SCRAPPED case on OffcutRemovalEnums.

So there is no weight, no cost and no trend - which is the headline quantity any quality objective about yield would be measured against, and what 8.7 and 9.1 both reach for. The nesting cost model already computes scrap per bar; nothing persists it.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Scrap is a row with a weight, a value, and the batch and bar it came off
- [ ] #2 The quarterly cleanout, which already proposes dead stock and never acts on it, writes scrap rows when somebody accepts the proposal
- [ ] #3 Scrap per month, per project and per product category is reportable without re-running a nest
- [ ] #4 The figure reconciles against the per-bar scrap in nested_state for a batch nested before this existed, or it is explained why it cannot
<!-- AC:END -->
