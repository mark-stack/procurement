---
id: TASK-006
title: Scrap as a record
status: Done
assignee: []
created_date: '2026-10-01 01:55'
updated_date: '2026-10-05 23:41'
labels:
  - iso-9001
  - clause-8.7
  - clause-9.1
  - scrap
  - minor
dependencies: []
priority: medium
type: feature
ordinal: 3000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
app/Models/Scrap.php is an empty class, ScrapController is seven empty stubs, and Batch::scrap() returns an empty collection with a todo on it. Scrap exists only as a per-bar number inside the nested_state JSON and as the SCRAPPED case on OffcutRemovalEnums.

So there is no weight, no cost and no trend - which is the headline quantity any quality objective about yield would be measured against, and what 8.7 and 9.1 both reach for. The nesting cost model already computes scrap per bar; nothing persists it.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Scrap is a row with a weight, a value, and the batch and bar it came off
- [x] #2 The quarterly cleanout, which already proposes dead stock and never acts on it, writes scrap rows when somebody accepts the proposal
- [x] #3 Scrap per month, per project and per product category is reportable without re-running a nest
- [x] #4 The figure reconciles against the per-bar scrap in nested_state for a batch nested before this existed, or it is explained why it cannot
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
`scraps` is a real table again (2026_10_06_100000_make_scrap_a_record), replacing the February 2025 shape nothing had ever written to. A row carries the drop in millimetres, what it weighed, what owning it had cost landed, what the weighbridge pays back, the mass per metre the three were worked out from and whether that mass was the catalogue's or the business default - plus the batch, and the bar or offcut it came off. `scrapped_at` is separate from `created_at` so a backfilled row lands in the month the steel was destroyed.

One writer: `Services\ScrapLedger`. `recordNest()` reads a batch's saved nest rather than being handed what to write, which is what makes the live path and the backfill the same code - so the per-bar scrap the nesting screens have always shown and the rows in this table are two readings of one number. `recordCleanout()` takes the cleanout candidate row, so the money recorded is the money the page showed rather than a second opinion. `CreateBarsAndOffcuts` now also stamps `bar_ids` into nested_state beside the offcut ids it already wrote, which is how a drop names the bar it came off.

Scoping is through `batch_id` and nothing else - no `business_id` column - so `Scrap::scopeOfBusiness` inherits the sandbox filter batches already carry, and `SandboxCleaner`'s existing `Scrap::whereIn('batch_id', ...)` line finally has rows to delete.

`Services\ScrapReport` answers by month (every month in the window, including the empty ones), by product category and by job, in three queries with no nesting. A drop off a bar shared by several jobs is split between them in proportion to the steel each took off that bar, read from `cuts`; a cleanout belongs to no job and says so rather than being spread over whatever was running. `/scrap` renders it, linked from the offcuts page and the nav.

`php artisan scrap:backfill` reconstructs the history. Run against the dev database it wrote 20 rows across 4 batches and every one reconciles with its nested_state (3000/1000/2000/600mm). Where the bars cannot be paired one-for-one - a deleted bar, or a batch older than `bars.batch_id` - the weight is still recorded and the row names no bar, because a wrong bar reads as traceability. What it cannot recover is the cost coefficients of the day: the kilograms are history, the dollars are today's valuation of it.

Restoring a scrapped offcut now deletes its cleanout row, or the same steel would be counted as destroyed and sitting on the rack at once.

15 new tests in tests/Feature/ScrapRecordTest.php. Suite 1016 passed / 29 risky. PHPStan unchanged at 22 (HEAD was already 22, not 23 - the baseline line in docs/quality-gates.md had drifted before this change).
<!-- SECTION:NOTES:END -->
