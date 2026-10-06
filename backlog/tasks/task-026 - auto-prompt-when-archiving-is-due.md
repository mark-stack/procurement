---
id: TASK-026
title: auto moving to past projects
status: Done
assignee: []
created_date: '2026-10-01 10:39'
updated_date: '2026-10-02 10:30'
labels: []
dependencies: []
ordinal: 4000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
when a card in delivering column has all orders delivered, auto move it into past projects after 5 days
<!-- SECTION:DESCRIPTION:END -->

## Decisions

<!-- SECTION:DECISIONS:BEGIN -->
> Renamed 2026-10-04, when the app settled on one word for a job being over: this service is now
> `App\Services\DeliveredBatchAutoDone`, its command `batches:mark-delivered-done`, and
> `archiveDueDate()` is `doneDueDate()`. KanbanMinimalCard was deleted with the board; the badge is
> drawn by the Nesting page. The names below are the ones used at the time.

App\Services\DeliveredBatchArchiving, swept daily by `batches:archive-delivered`, five days after the
LAST delivery on the batch was booked in. The card says the date it will go
(KanbanMinimalCard, "Closes 7 Oct 26 (5d)") so it never disappears unannounced.

Nothing in the app re-opens a closed batch, so the sweep holds off rather than guesses. It leaves a
card on the board - for a person to close by hand - when:

 - a sent order has not been delivered (the column starts at "ordered", not "arrived");
 - the batch is really in Ordering: a material row that never matched a product is steel nobody
   bought, and BatchStages reads that correctly;
 - a delivered order has no received_at. Those are deliveries booked in before the goods receipt
   columns (2026-10-01). The migration refused to backfill them from updated_at and this refuses to
   read it, so the batches delivered before that date are closed by hand or not at all;
 - a delivered steel merchant order is missing its material certs. The card's warning is the only
   thing chasing them, and the button is already hidden behind it;
 - the business is read-only on billing. They could not undo it once they paid.

`done` now has one writer, App\Actions\Batch\MarkAsDone, shared by the button and the sweep.

Deliberately not built: no notification. The date on the card is the warning, and unlike the
fabrication sweep there is nothing for the user to go and do about it.

Left open: nothing anywhere restores a closed batch, and this is the first thing that closes one
without a person pressing anything. An "undo" on Past Projects is worth more now than it was.
<!-- SECTION:DECISIONS:END -->
