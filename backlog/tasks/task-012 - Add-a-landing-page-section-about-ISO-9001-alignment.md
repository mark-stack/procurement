---
id: TASK-012
title: Add a landing page section about ISO 9001 alignment
status: To Do
assignee: []
created_date: '2026-10-01 03:51'
labels:
  - iso-9001
  - marketing
  - landing-page
dependencies:
  - TASK-011
references:
  - resources/js/Pages/Welcome.vue
  - app/Http/Controllers/LandingController.php
priority: medium
ordinal: 57500
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The ISO 9001 work from #50 and the tickets behind it are invisible to anyone who has not read the repo. A fabricator shopping for a nesting tool while chasing or holding certification has no way to see that item-level traceability, goods receipts and an append-only change log are already in the product, so the landing page should say so.

The constraint is TASK-011, and it is the reason this ticket depends on it. That ticket settles which clauses this product supports and which it does not, and requires that marketing copy never claim ISO 9001 compliance for the product itself. The honest claim is narrow: the product supports a fabricator's QMS for clause 8 and touches nothing else. It holds no context of the organisation, no quality policy or objectives, no internal audit, no management review and no CAPA register. Writing this section before TASK-011 is decided risks shipping exactly the overclaim that ticket exists to prevent.

Welcome.vue is where it goes - the landing page is that single Inertia page behind LandingController, around 891 lines of sections, with the nesting and pricing sections carrying ids already. A new section wants the same treatment so it can be linked to directly.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 A section on the landing page naming the clause 8 capabilities that exist today: traceability, goods receipt, change log
- [ ] #2 Copy says the product supports a fabricator's QMS and never says the product is ISO 9001 compliant or certified
- [ ] #3 Wording matches the scope statement from TASK-011 rather than being drafted independently of it
- [ ] #4 What a customer still has to hold outside this system is stated, not implied by omission
- [ ] #5 The section carries an id so it can be linked to, consistent with the nesting and pricing sections
<!-- AC:END -->
