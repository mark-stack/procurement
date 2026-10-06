---
id: TASK-009
title: Give order confirmation a writer
status: To Do
assignee: []
created_date: '2026-10-01 01:56'
labels:
  - iso-9001
  - clause-8.4.3
  - orders
dependencies: []
priority: low
type: enhancement
ordinal: 60500
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
orders.order_confirmation_received has existed since the first orders migration and no controller and no screen has ever set it - only a test helper. PR #50 added order_confirmation_received_at beside it knowingly, so that the missing half is a screen rather than a migration. Until then both stay null, which reads correctly as no confirmation recorded.

ISO 9001 8.4.3 is about the information given to and received from an external provider. The merchant acknowledging a purchase order is the received half of it.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Somewhere to record that a merchant acknowledged the order, with their reference
- [ ] #2 The date is written with the flag, the way order_sent_at is written with order_sent
- [ ] #3 The board can show an order that was placed and never acknowledged
- [ ] #4 The existing column keeps its meaning, so nothing that reads it has to change
<!-- AC:END -->
