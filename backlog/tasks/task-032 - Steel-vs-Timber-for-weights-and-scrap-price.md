---
id: TASK-032
title: Steel vs Timber for weights and scrap price
status: Done
assignee: []
created_date: '2026-10-06 06:59'
updated_date: '2026-10-06 19:30'
labels:
  - nesting
  - cost-model
  - catalogue
dependencies: []
ordinal: 66500
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
LVL is timber, not steel. It has a different weight and a different price, and the cost model knew neither: `NestingCostModel` read every coefficient off the Business and nothing else, so there was exactly one `material_cost_per_tonne` for the whole yard and every LVL nest was priced at the steel rate.

Live, not theoretical: 14 LVL rows in the catalogue (`material=TIMBER`, `supplierGroup=TIMBER_MERCHANT`), with 4 pieces, 10 bars and 10 scrap rows already recorded against them.

Two errors were cancelling, which is why it went unnoticed. The LVL rows carry no `kg_per_m`, so they fell back to `default_kg_per_m` at 10.0 against a true timber mass nearer 3.4 - three times over - while being priced at $2,000/t against a timber price nearer $4,000/t. $20.00 a metre by two compounding mistakes, where the honest arithmetic is around $14. **Correcting the mass alone would have halved the price and made the model worse.**

And the scrap credit was fiction: `scrapIncome()` pays back 13% of the bare price on every drop. A weighbridge buys metal; a timber merchant does not buy LVL offcuts back, and a skip of timber costs tip fees. Each of those ten live rows credits 52c nobody received.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A nest resolves its material prices against the merchant it buys from, not against one yard-wide steel price
- [x] #2 A merchant can be told the bin pays nothing, distinguishably from having said nothing
- [x] #3 The mass fallback is per merchant, so a timber row the catalogue cannot weigh is not costed as steel bar
- [x] #4 The yard's own coefficients stay yard-wide, and a business that buys only steel is unaffected
- [x] #5 A batch keeps the merchant rates it was nested on
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
`businesses.cost_overrides` (2026_10_06_170000) is a JSON map of supplier group => coefficient => value. JSON for the reasons `batches.nesting_settings` is JSON: what a reader wants is the whole override set as it stood, never a column to query on, and five coefficients across five groups is twenty-five columns that are null almost everywhere. It also means the set rides inside the retained snapshot without the snapshot learning anything new.

**Keyed by supplier group, not by material.** The supplier group is what the application already buys in - every `ProductImplementation` declares one, every order on a batch is split by one, and a piece can be asked for its own - so it is the axis that already reaches from a piece spec to a purchase order without anything new being threaded through. Material is the finer axis and would be the right one for stainless, which is dearer than plain carbon and comes from the same merchant; the catalogue holds exactly one stainless row today and it is a bolt, which is nested by counting packs and never costed. Written down in the migration so the next person does not have to re-derive it.

`NestingCostModel::MERCHANT_COEFFICIENTS` is the five that may differ: `material_cost_per_tonne`, both halves of freight, `scrap_recovery_rate` and `default_kg_per_m`. **The line is that these are the merchant's and everything else is the yard's.** The labour rate and every handling duration stay yard-wide - one crew, one wage, and a bundle of LVL is carried by the same people who carry a beam. `cut_minutes_per_kg_per_m` is the one that looks like it belongs on the merchant's side and does not: it is already scaled by the section's mass, so a lighter piece already gets a shorter cut, and what is left is blade hardness, which nobody has asked for and would be wrong to approximate with a merchant.

`setting()` now resolves merchant override → platform default → business column → `DEFAULTS`, merged per coefficient rather than per merchant (see the platform defaults below). **A merchant's zero is an answer, not an absence** - that is the whole point of `scrap_recovery_rate: 0` for timber, and it is why `SupplierGroupCosts::normalise` drops a blank and keeps a nought rather than doing anything truthy-shaped.

`Services\SupplierGroupCosts` holds the category → group map (memoised: resolving it constructs every implementation, and the cleanout asks once per offcut on the rack) and the normaliser. Normalise **drops** what it cannot honour; the form **refuses** the same input. That asymmetry is deliberate and documented in both places: a retained snapshot is written once and read back months later when a group may have been removed, and a nest that throws there is not an option - whereas a figure silently discarded on the way in is the worst outcome available, because the form comes back looking saved and the nest does not change.

Three call sites now pass the group: `NestingFormatter::costModel()` (off the piece spec's category), `OffcutCleanout` and `ScrapLedger` (both of which also had to put the group in their model cache key - two offcuts of the same mass and reference length are not the same costing question if one is steel and one is timber).

`Batch::duplicatedOrderOverhead()` was rewritten to deduct per merchant. The flat delivery fee is now per-group, so a deduction struck at the yard-wide rate would take back the wrong money. **How many duplicates are deducted is unchanged** - only the rate each is priced at moves, and only for a business that has set one - which is why the existing suite stayed green through it.

`NestingSettings::inForce()` retains the **effective** set under `OVERRIDES_KEY` - platform figures included, not just the business's own - for the same reason it resolves every flat coefficient before storing it: a snapshot recording only what a business had typed would silently re-read tomorrow's platform defaults, and a batch nested in March would cost differently in May because the platform changed its mind about timber. A merchant nobody has an opinion on is omitted entirely rather than stored as an empty object. `asOf()` needed no change: it builds an unsaved Business from the snapshot, and the new `'cost_overrides' => 'array'` cast is what makes a snapshot-built Business and a database-loaded one the same object to read from.

The admin nesting page grew a collapsible "What each merchant charges" table inside the existing dials form - saved by the same press, with the yard's figure as each box's placeholder, because an empty box only means something if you can see what it falls back to. Open by default when there is something in it. The inputs bind as strings, not `.number`, which is what keeps `""` distinguishable from `0`.

`SupplierGroupCosts::PLATFORM_DEFAULTS` carries the timber figures, rather than them being written onto each business. Same kind of thing as `NestingCostModel::DEFAULTS` and the same warning on it - a starting point, not a measurement of any particular yard, editable per business. They exist because the alternative is worse than being approximately right: left empty, every business *including one created tomorrow* prices timber as steel until somebody notices and fills in a form, and "somebody notices" is precisely what did not happen for the whole life of the LVL rows. **A platform default is wrong by a margin; no default was wrong by a factor.** $4,100/t (structural LVL at ~$14/m in 90x63, which at 3.4 kg/m is $4,100 a tonne), `scrap_recovery_rate` 0 - not an estimate, a weighbridge buys metal and nobody buys LVL offcuts back - and a 3.5 kg/m fallback. No entry for PURLINS, which will want one next, because nobody has given a figure and an invented one would be indistinguishable from a real one once it sat in the defaults.

Resolution is merchant override → platform default → business column → `DEFAULTS`, merged **per coefficient rather than per merchant**: a yard that types only its own timber price keeps the platform's "the bin pays nothing", instead of having to re-enter two figures it agrees with to change the third. `NestingSettings` retains the *effective* set, not the business's overrides, for the same reason it resolves every flat coefficient before storing it - otherwise a batch nested in March would re-cost in May because the platform changed its mind.

21 new tests in tests/Feature/MerchantCostsTest.php, including the live bug end to end: the same nest, the same drops, read back as timber, asserting the bin pays nothing and the mass is the timber mass. Suite 1093 passed / 29 risky (was 1070 / 29). PHPStan 22, unchanged.

Verified end to end against the real catalogue with no business configuration at all: an LVL E13 90x63 now costs $13.94/m with the bin paying nothing, against $20.00/m and a 52c-per-metre phantom rebate before.

`php artisan scrap:revalue` re-prices the rows whose money was struck through the wrong material. **A command, not a migration** - this restates history, which the rest of the application goes out of its way not to do, so it is something somebody runs on purpose rather than something that happens to every environment on deploy. **Dry run by default**; `--apply` writes.

What justifies it at all is that it corrects a MEASUREMENT, not a change of mind. The mass is a fact about the section - LVL 90x63 has always weighed 3.4 kg/m, the catalogue simply had no figure - and every row it touches already said so, with `kg_per_m_estimated` true on it. The price per tonne and the recovery rate still come from the batch's own retained snapshot; the only thing that reaches them is the merchant layer, and only where that snapshot is silent. Rows are re-valued **in place**: that the drop happened, how long it was and which bar it came off are facts, and re-running the ledger would re-derive the identity of each row, which is a much larger claim than the one being made.

**The dry run earned its keep immediately.** The first version matched a scrap row back to the catalogue on `ProductSpec`'s full key, which includes the purchasable variations - for a meterage product, `nominal_length`. A scrap row describes a REMNANT and carries no stock length, so nothing matched, every row fell back to the yard default, and it proposed "correcting" a perfectly good 17.70 kg/m PFC down to 10.0. Matching on the category's `mandatory` columns - the set `NestingFormatter::resolveKgPerM` uses - fixes it. There is a test named for that failure.

Run against the live catalogue: the 10 LVL rows went 10.0 → 3.4 kg/m, $40.00 → $27.88 written off, and $5.20 → $0.00 credited from a weighbridge that never saw them. The 10 steel rows on the same batches were correctly left alone, and a second run reports nothing to do.

**Still outstanding:** the platform figures are a sensible average, not this yard's. A business that knows its own timber price should type it in - the form is there and the placeholder shows what it would otherwise use.
<!-- SECTION:NOTES:END -->
