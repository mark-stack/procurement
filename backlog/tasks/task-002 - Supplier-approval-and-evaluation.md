---
id: TASK-002
title: Supplier approval and evaluation
status: To Do
assignee: []
created_date: '2026-10-01 01:55'
labels:
  - iso-9001
  - clause-8.4.1
  - suppliers
  - major
dependencies: []
priority: high
type: feature
ordinal: 43500
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The suppliers table is a name plus a PHP-serialized map of category flags. There is no approval status, no scope of approval (which grades or products a merchant is approved to supply), no record of the merchant holding their own certification, and no re-evaluation date. Nothing stops an order being placed against a merchant nobody ever approved.

ISO 9001 8.4.1 requires criteria for selection, evaluation, monitoring of performance and re-evaluation, with records retained.

The data for the performance half already exists and nothing aggregates it: quotes.quoted_lead_time against orders.received_at gives on-time delivery, Order::scopeMissingMaterialCerts gives certificate completeness per merchant, and orders.receipt_nonconformance gives the rejection reasons.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A supplier carries an approval status (approved, provisional, suspended) with who set it and when
- [ ] #2 A scope of approval records which supplier groups and grades the merchant may supply
- [ ] #3 Placing an order against an unapproved supplier warns, and the warning names who can approve
- [ ] #4 A scorecard per supplier reports on-time delivery, certificate completeness and recorded nonconformances over a chosen window
- [ ] #5 A re-evaluation date is held, and the supplier list shows which merchants are overdue
- [ ] #6 Approval changes land in record_changes, which Supplier already writes to
<!-- AC:END -->
