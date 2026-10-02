---
id: TASK-029
title: Dashboard
status: Done
assignee: []
created_date: '2026-10-01 22:40'
updated_date: '2026-10-02 00:32'
labels: []
dependencies: []
ordinal: 22000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
- Dashboard labelled as "Dashboard" in nav instead of "Upload materials"
- has multiple sections:
-     upload materials
-     status of current projects. maybe pills of "nesting", "quoting", "ordering", "delivering"
-     outstanding actions required (e.g. get quotes on a batch)

Built:
- Three sections, top to bottom: the four step counts with a row per live job, what needs doing, then the upload form (now Components/Dashboard/UploadMaterials.vue).
- Actions each link into the board, batch ones straight into its quotes/orders modal via ?quotes=. Project actions (unmatched material lines, no fabrication date) are only raised for the manager or the colleague who uploaded for them; batch actions are anybody's. Urgency comes off the fabrication date, so overdue work is read first.
- Which step a batch is on is now answered in one place (Services/BatchStages), which the board's Ordering and Delivering columns ask too - they each used to walk every project's material/piece/order tree to answer opposite halves of the same question.
- Pages/Dashboard.vue is the dashboard; the board is Pages/ProjectsBoard.vue, so the two screens stop sharing a name. Route names unchanged.
<!-- SECTION:DESCRIPTION:END -->
