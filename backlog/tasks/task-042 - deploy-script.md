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

**Not before the catalogue is seeded.** The 2026-10-08 02:35 deploy log has that dry run reporting
create 1117 / update 0 / unchanged 0, which means the products table on production is empty - so BOM
imports there are extracting nothing right now, and the first job is to fix that rather than to let
the deploy script do it.

The two paths are not interchangeable, and `--apply` is the wrong one for a first install.
MasterMaterialsSeeder::blankRow() writes `''` for a blank string column; SyncCatalogue writes `null`
(`array_fill_keys(ProductRules::EDITABLE, null)`). ProductSpec::canonical() folds the two together,
so anything going through fingerprint() - ProductUsage included - cannot tell the difference. But
Piece::product() is raw SQL equality across nine spec columns, and so is Product::pieces(): a
piece holding `''` against a product holding `null` matches nothing, returns null, and says so to
nobody.

So, in order:

1. once, by hand on production: `php artisan db:seed --class=MasterMaterialsSeeder --force`
2. then append `--apply`. After the seeder it is a no-op, and correct for every deploy after that

Also remove the `php artisan records:check-grant` line from the deploy script - that command is gone
as of the SQL-grant removal, and it is what has been failing the deploy.
<!-- SECTION:DESCRIPTION:END -->
