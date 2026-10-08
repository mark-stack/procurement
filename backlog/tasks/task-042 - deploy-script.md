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

**Blocked on TASK-043, and it is a first install.** The 2026-10-08 02:35 deploy log has that dry run
reporting create 1117 / update 0 / unchanged 0 / delete 0 / deprecate 0, because `DB_DATABASE` is
`procurement` - a fully migrated but completely empty schema. The older data in `steelnesting` is
being abandoned rather than migrated forward, so `procurement` stays and gets seeded.

`--apply` must not go in before that seeder has run, and it is not a substitute for it.
MasterMaterialsSeeder::blankRow() writes `''` for a blank string column; SyncCatalogue writes `null`
(`array_fill_keys(ProductRules::EDITABLE, null)`). ProductSpec::canonical() folds the two together,
so anything going through fingerprint() - ProductUsage included - cannot tell the difference. But
Piece::product() is raw SQL equality across nine spec columns, and so is Product::pieces(): a piece
holding `''` against a product holding `null` matches nothing, returns null, and says so to nobody.
A catalogue created from empty by `catalogue:sync` would not match the one every other environment
has, which is what the README has always said about these two not being interchangeable.

After the seeder, `catalogue:sync` reports 1117 unchanged and `--apply` is a no-op - which is both
the proof that the paths agree and the point at which it is safe in the deploy script.

Done: the `php artisan records:check-grant` line is out of the deploy script. That command is gone
with the SQL-grant removal, and it is what had been failing every deploy.
<!-- SECTION:DESCRIPTION:END -->
