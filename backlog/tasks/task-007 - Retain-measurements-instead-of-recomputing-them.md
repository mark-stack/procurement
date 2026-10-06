---
id: TASK-007
title: Retain measurements instead of recomputing them
status: Done
assignee: []
created_date: '2026-10-01 01:56'
updated_date: '2026-10-06 12:00'
labels:
  - iso-9001
  - clause-9.1
  - clause-6.2
  - reporting
  - minor
dependencies: []
priority: medium
type: enhancement
ordinal: 1000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
DownloadUsageController re-runs the nest against current data, and NestingCostModel reads the labour rate, the cost per tonne and the default mass per metre live - so a historic batch's recorded cost changes when a setting changes.

scrap_threshold_mm is snapshotted into nested_state per bar, which is the right instinct and the right pattern. Kerf and the cost parameters are not. Nothing retains a KPI over time, so there is no series to review a quality objective against, which is what 9.1 and 6.2 ask for.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The cost parameters in force are snapshotted onto the batch when a nest is saved, the way scrap_threshold_mm already is
- [x] #2 Kerf is snapshotted with them
- [x] #3 A historic batch reports the cost it was costed at, not what it would cost today
- [x] #4 A retained monthly series of yield, scrap and on-time delivery, readable as a trend without recomputation
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
`batches.nesting_settings` (2026_10_06_110000) is the snapshot: the saw kerf, the scrap threshold and every coefficient in `NestingCostModel::DEFAULTS`, taken at the moment `CreateBarsAndOffcuts` saves the nest and written before the scrap ledger reads it back. JSON rather than a column each - the cost model has grown a coefficient three times already, and what a reader needs is the whole set as it stood, never a column to query on. `DEFAULTS` is public now so there is one list rather than two that can drift.

`Services\NestingSettings` is the seam. `inForce()` takes the snapshot; `asOf()` hands it back as an unsaved `Business` carrying those numbers, so `NestingCostModel`, `NestingFormatter::kerf` and the threshold test all read it exactly as they read the real row - the pattern `NestingProof` already uses to cost its worked examples. A batch with no snapshot falls back to the live business, which is the behaviour that was always there. `ScrapLedger` now values drops through it, so the backfill and the live path finally agree about history as well as about millimetres: raising the steel price no longer restates what February destroyed, and `thresholdIn()` falls back to the retained threshold rather than today's.

`batch_measurements` (2026_10_06_120000) is one row per batch filled by two events. `Services\BatchMeasurements::recordNest` reads the SAVED nest - the same `usageStats` the Nesting cards draw - so the retained yield and the screen are two readings of one number; `recordDelivery` is called by all three presses that can complete a delivery (the card's "All delivered", the order list's per-merchant mark, a goods receipt) and the one that finishes the job records it. `usageStats` grew a `totalConsumed` key so a month's yield can be summed first and divided once; averaging per-batch percentages gives a two-cut job the same say as six tonnes, and there is a test for that.

The delivery half is why this had to be a record and not a query. A batch's required-by day is the earliest fabrication date among its jobs, and that date is shared, live state every manager on the batch may move - so `required_on` and `days_late` are copied onto the row at the delivery. Moving a date afterwards no longer turns a late delivery punctual; the test is named for the disaster.

`Services\MeasuresReport` puts the three series together by month in four queries with nothing re-nested, the scrap column being `ScrapReport`'s own figure rather than a second count of the same steel. `/measures` draws it (nav entry; scrap is one link off it), and a month with nothing in it reads as a dash rather than as nought per cent. Past Batches now carries each closed batch's yield, what it was costed at and whether it was late - `Batch::nestCost()` reads the figure the search actually picked the plan on, so a labour rate going up does not restate last month's job.

`php artisan measures:backfill` reconstructs the yield history from `nested_state`. It deliberately recovers neither the delivery (the day the steel was wanted is editable, so working it out now would measure an old delivery against a date possibly set after it landed) nor the cost coefficients - a backfilled row carries `cost_from_retained_settings = false` and the page says how many of them there are rather than printing both kinds of dollar the same way.

`DownloadUsageController` was named in the description and is deliberately left recomputing: it answers for the pending card, where there is no batch and no commitment, so today's rack and today's price book are the right things to forecast with. Its docblock now says so.

20 new tests in tests/Feature/RetainedMeasurementsTest.php. Suite 1036 passed / 29 risky (was 1016 / 29). PHPStan 22, unchanged by this work - the baseline line in docs/quality-gates.md was still reading 23 after an earlier fix and is now corrected, with the stale line numbers in its breakdown table.
<!-- SECTION:NOTES:END -->
