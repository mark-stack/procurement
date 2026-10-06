---
id: TASK-001
title: 'Item-level traceability, goods receipt and an append-only change log'
status: Done
assignee: []
created_date: '2026-10-01 01:55'
updated_date: '2026-10-01 02:42'
labels:
  - iso-9001
  - clause-8.5.2
  - clause-8.6
  - clause-7.5.3.2
  - major
dependencies: []
references:
  - 'https://github.com/mark-stack/procurement/pull/50'
priority: high
type: feature
ordinal: 5000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The first three majors off the gap analysis, shipped together in PR #50.

1. Item-level traceability (8.5.2). bars gained order_id and heat_number, and a new cuts table joins a piece to the bar or offcut it was taken from - one row per physical cut, because actual_qty of 5 is five parts that the nest places separately and that routinely land on different bars. The chain runs piece to cut to bar to heat number, and Cut::originBar walks the offcut ancestry so steel three generations off the rack still names the bar it was rolled as.

2. Goods receipt (8.6, 8.4.3). orders gained received_at, received_by_user_id, a docket number, two tri-state verification checks, a nonconformance reason and a note, plus order_sent_at. Nothing was backfilled: updated_at would have been a plausible-looking guess at a date somebody checked steel, which is the one thing such a record must never contain.

3. Append-only change log (7.5.3.2, 8.5.6, 5.3). record_changes records every create, update and delete on Order, Piece, MaterialCertificate, Product, Template and Supplier, with the columns that moved, the before and the after, who did it, and the impersonating admin where there was one. A certificate can no longer be deleted off a placed order.

819 tests pass, PHPStan unchanged at 23.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A part can be traced to the heat it was rolled from, not to a list of certificates it might have come off
- [x] #2 A delivery records who checked it, when, against what docket, and what was wrong with it
- [x] #3 Every change to an order, piece, certificate, product, template or supplier is recorded with its before and after
<!-- AC:END -->
