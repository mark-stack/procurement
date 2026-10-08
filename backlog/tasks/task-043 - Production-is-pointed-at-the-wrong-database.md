---
id: TASK-043
title: Start production clean on the database it already points at
status: In Progress
assignee: []
created_date: '2026-10-08 10:24'
updated_date: '2026-10-08 10:40'
labels:
  - ops
dependencies: []
priority: high
ordinal: 76500
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The site's DB_DATABASE is 'procurement', an empty but fully migrated schema - 0 products, 0 users, 0 projects, 0 uploads. A second schema on the same MySQL server, 'steelnesting', holds ~1105 products and the older data. Found while chasing a deploy failure on 2026-10-08: catalogue:sync planned 1117 creates against 0 unchanged and 0 deletes, which only happens when the existing platform set is empty - it was reading the empty schema.

**Decided 2026-10-08: nothing on that server matters, so 'steelnesting' is abandoned rather than migrated forward.** That is what makes this cheap. 'procurement' is already at the committed migration set - the deploy reported "Nothing to migrate" - so there is no drift to resolve, no pointer to change, no Forge environment to hunt through and no restore. The catalogue is seeded into the database the site is already using.

What goes with 'steelnesting': the businesses, their users, the uploads, and the learned import templates. The templates are the only part of that worth a second thought, because they are built from real customer spreadsheets and cannot be reconstructed without them. Accepted knowingly.

The seeder and not catalogue:sync --apply, because this is a first install and the two are not interchangeable. MasterMaterialsSeeder::blankRow() writes '' for a blank string column; SyncCatalogue writes null. ProductSpec::canonical() folds those together, so anything going through fingerprint() cannot tell the difference - but Piece::product() and Product::pieces() are raw SQL equality across nine spec columns, where a piece holding '' against a product holding null matches nothing and says so to nobody. See TASK-042.

Steps:

1. php artisan db:seed --class=MasterMaterialsSeeder --force
2. php artisan catalogue:sync with NO --apply, as the check. It should report 1117 unchanged and 0 create - which is also the proof that the seeder's blanks and the committed file agree, and that --apply is safe to add to the deploy script afterwards
3. Register the ADMIN_EMAIL account and set its users.is_admin by hand. Nothing in the UI grants it, and the migration only backfills rows that already existed, so a fresh database has no admin at all
4. Drop the 'steelnesting' schema once the site is confirmed working. Leaving a second plausible-looking schema beside the live one is how this evening happened

Not chased, deliberately: why DB_DATABASE said 'procurement' in the first place. It is the database being kept, so the answer no longer changes anything. Worth knowing if the pointer ever moves on its own again.

Unblocks TASK-042.

**Done 2026-10-08 10:44.** An earlier attempt had filled the catalogue by the wrong path, so the platform products were deleted and the seeder run against the empty table. Verified on the server afterwards, and all four signals agree: products=1117 and platform=1117, every created_at inside one second (a single insert batch), no product rows in record_changes at all - which the seeder cannot write, because insert() bypasses model events, and catalogue:sync --apply would have left 1117 of them - and NULL=0 on every blank string column, against empty_string counts running to 1117. That last one is the whole point: the spec columns are an unenforced join key matched by raw SQL equality in Piece::product() and Product::pieces(), where '' and NULL are not equal.

Also settled, which is why --apply is safe in the deploy script from here: MaterialsJsonImport::changes() compares through ProductSpec::canonical(), which folds '' and NULL together, so a sync against a seeded catalogue reports no change and cannot undo the convention. The hazard was only ever creating a catalogue from empty.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The platform catalogue is seeded into the database the site points at, by the seeder and not by catalogue:sync
- [x] #2 The seeded catalogue is verified on the server: 1117 platform rows, one insert batch, no product change-log rows, NULL=0 on every blank string column
- [ ] #3 An admin account exists, with users.is_admin set by hand
- [ ] #4 A BOM upload extracts pieces, which is the thing an empty catalogue silently broke
- [ ] #5 The steelnesting schema is dropped, its loss having been accepted knowingly
<!-- AC:END -->
