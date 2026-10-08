---
id: TASK-042
title: deploy script
status: In Progress
assignee: []
created_date: '2026-10-08 01:56'
updated_date: '2026-10-08 03:10'
labels: []
dependencies: []
ordinal: 2000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
append "--apply" to "$FORGE_PHP artisan catalogue:sync"

**Blocked on TASK-043: the site is pointed at the wrong database.** The 2026-10-08 02:35 deploy log
has that dry run reporting create 1117 / update 0 / unchanged 0 / delete 0 / deprecate 0, which was
read at the time as an empty catalogue. It is not. `DB_DATABASE` is `procurement`, a fully migrated
but completely empty schema - 0 products, 0 users, 0 projects, 0 uploads - while the live data is in
`steelnesting` (~1105 products). Confirmed by tinker on the server.

So `--apply` must not go in until that is fixed, and neither must a seeder run. Against `procurement`
either one would fill the wrong database and make the problem look solved while the real data sat
orphaned beside it.

Once the environment points at `steelnesting`, this stops being a first install: the sync reconciles
~1105 existing rows against the committed 1117 rather than creating from nothing, which is the path
it was written for. Read the plan without `--apply` first - the counts will say what five years of
hand-editing did to that catalogue - and the `''` versus `null` difference between
MasterMaterialsSeeder::blankRow() and SyncCatalogue is invisible on an existing catalogue, so it does
not bear on the decision any more. It would have, on an empty one: ProductSpec::canonical() folds the
two together, but Piece::product() and Product::pieces() are raw SQL equality across nine spec
columns, where a piece holding `''` against a product holding `null` matches nothing and says so to
nobody.

Done: the `php artisan records:check-grant` line is out of the deploy script. That command is gone
with the SQL-grant removal, and it is what had been failing every deploy.
<!-- SECTION:DESCRIPTION:END -->
