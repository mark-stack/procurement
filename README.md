
## Steelnesting.com.au

### Getting started:
- Create database called "procurement"
- php artisan migrate --seed
- composer run dev (starts the server, queue worker, log tail and Vite together — leave it running)
- Login as admin (mark.laravel.coder@gmail.com, Password123#)
- Need import master material list from Excel. Nav > dropdown > "Update Materials (Admin)". Allow 10 seconds then click away from 'done' incomplete modal

### Production setup:
- PHP 8.5 with ext-gd, MySQL 8, Node (to build assets only), cron, and a process supervisor
- `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`
- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` (`php artisan key:generate`), `APP_URL`,
  the DB block, the `MAIL_*` block, and `LOGIN_AVAILABLE=true` once sign-up is meant to be self-serve.
  Leave `TEST_MODE` out entirely - it defaults to false, and true mails every project manager once a
  minute. `SESSION_SECURE_COOKIE` and Telescope look after themselves on `APP_ENV=production`
- `php artisan migrate --force`, then **on a brand new database only**,
  `php artisan db:seed --class=MasterMaterialsSeeder --force`. Never plain `--seed`:
  `DatabaseSeeder` also creates the SAMPLE business, users and projects. The catalogue is not
  optional - an empty `products` table makes every BOM import extract nothing. It lives in
  `database/seeders/Data/MasterMaterials.php`, so a clone has it and no disk is involved
- **Every deploy after that: `php artisan catalogue:sync --apply`**, as a line in the Forge deploy
  script after `migrate --force`. It reconciles this database to the committed catalogue - creating
  what is missing, updating what differs, and for anything the file no longer lists, deleting it
  where nothing refers to it and deprecating it where something does. Idempotent, so a deploy that
  did not touch the catalogue is a no-op, and it exits non-zero (failing the deploy) on an invalid
  file or a suspiciously large number of deletions. Run it without `--apply` first to read the plan
- Those two are not interchangeable, and the seeder is the one that does a first install. It writes
  `''` for a blank string column, which is what the catalogue has always held and what the spec
  columns are matched on; `catalogue:sync` goes through `ProductRules` and writes `null` for the
  same column. That difference is invisible on an existing catalogue - sync reports no change
  either way - but a catalogue created from empty by `catalogue:sync` would not match the one every
  other environment has
- `php artisan config:cache route:cache view:cache` as a deploy step. Anything read with `env()`
  outside `config/` returns null afterwards, which is why the kill switches live in `config/`
- The append-only grant, once per server, again after any restore, and again after any deploy whose
  migrations added a table: the application's MySQL user wants INSERT and SELECT on `record_changes`
  and `record_dispositions` and no UPDATE or DELETE on either.
  `php artisan records:check-grant --sql --user=procurement@%` prints the statements for the current
  schema; run them as an account that can grant. Then `php artisan records:check-grant` as the last
  step of every deploy - nothing breaks when that grant is missing, which is the problem with it. It
  runs daily from the scheduler too. [docs/records-retention.md](docs/records-retention.md) has the
  whole arrangement, including why it is table by table
- Retention and disposition: periods live in `config/retention.php`, nothing on a schedule deletes a
  record, and `php artisan records:dispose` with no arguments reviews what is past its period
  without touching anything. Same document
- Cron: `* * * * * php artisan schedule:run` - deadline reminders, trial reminders, the quarterly
  offcut cleanout, the daily prune of the spreadsheets kept against failed template-learning
  attempts, and the daily check on the append-only grant. All idempotent, so a missed hour
  self-corrects
- A supervised `php artisan queue:work` (database driver). `NOTIFICATIONS_MAIL_REMINDERS=true` turns
  the reminder emails on; off, the nav bell still fills
- The admin: register the `ADMIN_EMAIL` account, then set its `users.is_admin` by hand. The migration
  only backfills rows that already existed, and nothing in the UI grants it - that is the point
- `OPENAI_API_KEY` is no longer optional in practice. It is what lets a customer's first upload write
  its own import template, which is the whole of how a new business sets itself up - without a key
  every unrecognised spreadsheet becomes a failed attempt on the admin templates screen for somebody
  here to finish by hand. `TEMPLATE_LEARNING_ENABLED=false` switches the feature off deliberately
- Health check: `/up`. Optional: `BILLING_DRIVER=stripe` with `STRIPE_SECRET`,
  `STRIPE_WEBHOOK_SECRET` and a price id per card-paid plan
- `php artisan billing:check` after any change to the billing environment, and once on the server
  after switching `BILLING_DRIVER`. It asks Stripe about every price id in `config/billing.php` and
  compares amount, currency, interval, tax behaviour and live/test mode against what the billing
  page quotes. Worth the step because a wrong price id has no symptom: `canSell()` only asks whether
  an id is set, so a typo, an archived price or a test-mode id under a live key takes the plan off
  the billing page in silence and the site goes on selling whatever is left. `--strict` fails on
  warnings too, for a deploy step that should stop

### Create a project:
- Projects page > "+add project to nesting". Modal will popup
- Project and upload example materials list (public > examples > material_list.xlsx)

### A customer uploading a format we have never seen:
- There is no onboarding step and no Activate button. A business can import from its first upload,
  and the trial runs from registration because that is when the product starts working
- An upload matching none of the business's templates is read by `TemplateProposalService`, the
  proposal is run through the real importer by `TemplateTestService`, and if every check that would
  stop an admin saving it passes, the template is recorded live and the file imports. The customer
  sees one extra sentence naming the template that was written for them
- It is the same gate either way: `TemplateTestChecklist::passed()` is what `StoreTemplateRequest`
  enforces for a template an admin types, and what this is refused by. A proposal that fails is not
  saved at all - not live, not inactive
- A failure keeps the spreadsheet, the proposal and every check as a `template_learning_attempts`
  row, emails every admin, and tells the customer we have the file - never to email it in. Admin >
  the business > Templates leads with those: "Open in the form" fills the form in with the proposal
  that failed, next to a download of the same file
- Recording a template re-reads every unresolved attempt for that business and closes the ones the
  new template now finds, so one customer's format arriving four times is one piece of work
- Bounded by `TEMPLATE_LEARNING_HOURLY_LIMIT` (default 5 attempts per business per hour - each one is
  two OpenAI calls and a full extraction) and `TEMPLATE_LEARNING_SAMPLE_RETENTION_DAYS` (default 30,
  after which the customer's spreadsheet is deleted and only the account of the failure is kept)

### Test mode (the sandbox):
- Account menu > "Test mode". A violet banner then sits above every page, and the board shows only
  what you make from that point on
- Projects and batches created in test mode are stamped with your user id and are invisible to
  everybody else, including colleagues in their own test mode. Switching back out leaves them
  where they are - it is a view, not a deletion
- Suppliers, the price book, products and templates are deliberately NOT sandboxed. The point is to
  try a real BOM against the real merchants
- "Clear everything" in the banner deletes the lot: projects, batches, material lists, quotes,
  orders, offcuts, bars, scrap and any reminders raised about them. Nothing live is reachable
  from it
- The hourly reminder checks run with nobody logged in, so they read live data only - a test
  project is never chased by email

### Record an import template:
- Admin > a business > Templates > "Fill this in from a sample": upload one of that customer's
  spreadsheets and the form fills itself in, with every check it ran against the file next to it
- A table an existing template already matches is placed exactly - the cells are worked out from
  where its heading row was found, which is what the importer reads. Anything else is read by
  OpenAI and marked "suggested by AI"
- Templates are rows in the `templates` table, not config. Marking one Active is what makes its
  spreadsheet import; a template belongs to one business. Recording one is a live change, and needs
  no deploy
- A template a customer's upload wrote for itself carries `generated_by_ai` with no `reviewed_at`,
  and the list shows those as "not reviewed". It is a reading list, not a gate - the template is
  already live, because gating it would put a customer back to waiting on an admin
- The AI half needs OPENAI_API_KEY (and optionally OPENAI_MODEL) in .env. Without it the form is
  still filled in for any spreadsheet an existing template already matches. The sample's contents
  are sent to OpenAI when a key is set
