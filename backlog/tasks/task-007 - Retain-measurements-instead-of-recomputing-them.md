---
id: TASK-007
title: Retain measurements instead of recomputing them
status: To Do
assignee: []
created_date: '2026-10-01 01:56'
labels:
  - iso-9001
  - clause-9.1
  - clause-6.2
  - reporting
  - minor
dependencies: []
priority: medium
type: enhancement
ordinal: 39500
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
DownloadUsageController re-runs the nest against current data, and NestingCostModel reads the labour rate, the cost per tonne and the default mass per metre live - so a historic batch's recorded cost changes when a setting changes.

scrap_threshold_mm is snapshotted into nested_state per bar, which is the right instinct and the right pattern. Kerf and the cost parameters are not. Nothing retains a KPI over time, so there is no series to review a quality objective against, which is what 9.1 and 6.2 ask for.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 The cost parameters in force are snapshotted onto the batch when a nest is saved, the way scrap_threshold_mm already is
- [ ] #2 Kerf is snapshotted with them
- [ ] #3 A historic batch reports the cost it was costed at, not what it would cost today
- [ ] #4 A retained monthly series of yield, scrap and on-time delivery, readable as a trend without recomputation
<!-- AC:END -->
