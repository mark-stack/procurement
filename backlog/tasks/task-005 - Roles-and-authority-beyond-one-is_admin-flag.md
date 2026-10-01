---
id: TASK-005
title: Roles and authority beyond one is_admin flag
status: To Do
assignee: []
created_date: '2026-10-01 01:55'
labels:
  - iso-9001
  - clause-5.3
  - access
  - minor
dependencies: []
priority: medium
type: feature
ordinal: 5000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
is_admin is the whole role model, and inside a business every user is equal: anybody can place an order, book a delivery in, remove an offcut, scrap the rack or delete a supplier.

"Sent order" settles the approval for every project on the batch on one press. UpdateOrderApprovalStatus now honestly records whose press it was, which was the right fix, but there is no authority limit, no value threshold and no second signature - and NotificationEnums::ORDER_APPROVAL_REQUIRED is deliberately unbuilt, on the grounds that asking for an approval the application will not then wait for is worse than not asking.

ISO 9001 5.3 wants responsibilities and authorities assigned, communicated and understood. The system can currently express one bit of that.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A role per user within a business, at minimum separating who may commit money from who may not
- [ ] #2 An ordering authority limit by value or supplier group, and a defined behaviour when it is exceeded
- [ ] #3 An explicit decision on whether per-project-manager approval gets built, written down either way
- [ ] #4 If it is built, ORDER_APPROVAL_REQUIRED gets a writer and the order actually waits
- [ ] #5 A user's role is visible to their colleagues, not only to the platform admin
<!-- AC:END -->
