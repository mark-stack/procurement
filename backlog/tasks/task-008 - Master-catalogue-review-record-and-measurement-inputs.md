---
id: TASK-008
title: Master catalogue review record and measurement inputs
status: Done
assignee: []
created_date: '2026-10-01 01:56'
updated_date: '2026-10-06 18:00'
labels:
  - iso-9001
  - clause-7.1.5
  - catalogue
  - minor
dependencies: []
priority: medium
type: task
ordinal: 59500
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
default_kg_per_m is 10.0 and is the silent fallback for any product with no mass recorded, feeding both cost and tonnage. The catalogue has known-bad rows: a grade sitting in a material column (SS316), three RHS products with no description at all. products.certificates is tri-state, which is honest - null means nobody has said - but nothing audits how many rows are still null. And nothing records when the catalogue was last reviewed, or by whom.

ISO 9001 7.1.5 is about the suitability of the resources used for monitoring and measurement. A mass per metre that decides a tonne price is one of those.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A record of when the catalogue was last reviewed and by whom
- [x] #2 A report of rows that cannot be trusted: no mass per metre, an unanswered certificates flag, a spec column holding the wrong kind of value
- [x] #3 The 10.0 fallback is either removed, or made visible wherever a figure derived from it is shown
- [x] #4 The known-bad rows are corrected, or deliberately accepted with the reason recorded
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
`catalogue_reviews` (2026_10_06_150000) is a SERIES, not a `reviewed_at` column. A single column would answer "when was it last done" and destroy the previous answer every time it was done again, and "reviewed every quarter" is a claim about a sequence. Each row carries the date, the admin (`nullOnDelete`, like `templates.reviewed_by_user_id` - a departed admin's review still happened), an optional note, how many active products there were, and the per-reason tallies as JSON. The tallies are COUNTED SERVER SIDE at the moment of the review, off the same report the screen was showing: an admin recording "all good" over thirty unmeasured rows would be recording a claim, and this has to be a reading. `AdminCatalogueReviewController` is append-only and deliberately not idempotent - that is the one way it differs from `AdminTemplateReviewController`, where a second press is not a correction.

`Services\CatalogueTrust` is the report, and it is four kinds of wrong rather than one count, because they have four different answers: `NO_MASS` (a figure that will be guessed at - the one that moves money), `CERTIFICATES_UNANSWERED` (null, which `where('certificates', true)` and `where('certificates', false)` both exclude, so the product is asked for no certificate AND reported as missing none), `INVALID_VALUE` (a grade in a material column, a backtick typed for a 1), `NO_DESCRIPTION`. `ProductResource::invalidValues()` moved here so the screen, the importer's refusals and the report are one definition.

Two decisions inside it are load-bearing. **BUNDLE is excluded from `NO_MASS`**: 461 of the catalogue's 492 massless rows are bolts, nuts and studs, a fastener is nested by counting packs and is never handed to the cost model at all, and flagging all 461 would have buried the thirty sections that matter. **Deprecated rows are excluded entirely**: `availableForBusiness()` drops them, so no nest resolves a mass from one and its blanks cost nothing. The filter is a list of ids rather than SQL predicates because "this measurement is not a number" is a different expression in mysql and in the sqlite the tests run on - and an empty flagged set filters to nothing, never to the whole catalogue.

`products.accepted_reason` is the other half of a review. Some flagged rows are never going to be corrected - the catalogue's only stainless hex bolt carries its grade in its description, seven LVL rows have no grade because nobody grades LVL that way - and a report that cannot reach zero is a report nobody reads. An accepted row stays counted in its own column rather than being hidden, so the list can still say what the decision was. It is in `ProductRules::EDITABLE`, so it round-trips through the JSON export/import and an acceptance made in one environment is not lost on the next sync; who accepted it and when is already written by `RecordsChanges`, so the form does not ask.

The screen carries both at the top, above the catalogue rather than on a page of its own: the findings are the list to work through, recording the review is what you do once you have, and a report nobody passes on the way to the catalogue is a report nobody reads. Each count is the filter that shows its rows.

AC #3: the fallback is KEPT - a nest must cost something, and a product the catalogue cannot price still has to be nestable - and made visible everywhere a figure rests on it. `scraps.kg_per_m_estimated` was already written per row and read by nothing; `ScrapReport` now carries `estimated_weight_kg` through `tally()` and the per-project split (as a share, so the parts still add back up), and the Scrap and Measures pages say how much of the headline was worked out from an assumed mass. The Measures page matters most: an objective is a number with a target, and that is where one gets set. The offcut cleanout tab already said it per row. Nothing else shows a mass- or money-derived figure - the Nesting page, the offcut lists and the order list show none, and the admin algorithm page states the kg/m of every worked example.

AC #4: the three 12m RHS rows with no description (75x50x2.0, 2.5 and 5.0) each had an 8m twin directly above them that was described, and the twin's description names no length - somebody filled the column down the 8m block and stopped. Corrected in `master_materials.csv` (tracked, so the seeder propagates it) and by 2026_10_06_160000, which builds each description from the row's own dimensions in the format its siblings use, touches only a blank one, and is deliberately not reversible. The `SS316`-in-a-material-column row named in the description was corrected on 2026-09-27; `INVALID_VALUE` now reports 0, which is what confirms it.

**The 30 unweighed rows are now weighed** (2026_10_06_180000), so the trust report reads 0 outstanding on every reason. Each mass is COMPUTED FROM THE DIMENSIONS THE ROW ALREADY HOLDS rather than copied from a table, so the arithmetic can be checked without trusting the migration and a catalogue that differs between environments gets each of its own rows answered:

- **CHS, 12 rows, the whole 200nb family.** `pi x t x (OD - t) x 7850`, the AS/NZS 1163 definition, across `precise_width` - because 200nb is a nominal BORE and the tube is really 193.7mm, so a mass computed from 200 comes out a fifth heavy. Lands on 21.0, 23.3, 27.8, 36.6, 45.3 and 55.9: the published figures to the decimal. **The same formula reproduces every CHS mass the catalogue already held**, which is what says it is the right formula. These were the worst of the thirty - a 200nb at 45.3 kg/m had been costed at 10.0, under a quarter of its real material value.
- **FLAT, 1 row.** `w x h x 7850`. 75x8 is 4.71, also the published figure.
- **ALLTHREAD, 3 rows.** Mean of major and minor diameter, since a thread cuts material away: 0.75 for M12 and 1.37 for M16, against trade figures near 0.75 and 1.35.
- **LVL, 14 rows.** Cross-section x 600 kg/m3. The one genuinely estimated family, because timber varies with species and moisture in a way steel does not - but an estimate of timber rather than the 10.0 it had, which was an estimate of steel. It had to be done **together with** the timber merchant's price per tonne (TASK-032); correcting the mass alone would have halved the price and made the model worse.

The same figures are in `master_materials.csv` so a fresh seed carries them.

22 new tests in tests/Feature/CatalogueReviewTest.php (including the Material column on the catalogue screen). Suite 1093 passed / 29 risky (was 1050 / 29). PHPStan 22, unchanged - moving `invalidValues()` out of `ProductResource` and reading the reviewer through `instanceof` rather than `?->name ?? ...` kept it level.
<!-- SECTION:NOTES:END -->
