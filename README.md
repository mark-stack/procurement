
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
- `php artisan migrate --force`, then `php artisan db:seed --class=MasterMaterialsSeeder --force`.
  Never plain `--seed`: `DatabaseSeeder` also creates the SAMPLE business, users and projects. The
  catalogue is not optional - an empty `products` table makes every BOM import extract nothing
- `php artisan config:cache route:cache view:cache` as a deploy step. Anything read with `env()`
  outside `config/` returns null afterwards, which is why the kill switches live in `config/`
- Cron: `* * * * * php artisan schedule:run` - deadline reminders, trial reminders and the quarterly
  offcut cleanout. All idempotent, so a missed hour self-corrects
- A supervised `php artisan queue:work` (database driver). `NOTIFICATIONS_MAIL_REMINDERS=true` turns
  the reminder emails on; off, the nav bell still fills
- The admin: register the `ADMIN_EMAIL` account, then set its `users.is_admin` by hand. The migration
  only backfills rows that already existed, and nothing in the UI grants it - that is the point
- Health check: `/up`. Optional: `OPENAI_API_KEY` for the template parser, and `BILLING_DRIVER=stripe`
  with `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` and a price id per card-paid plan

### Create a project:
- Projects page > "+add project to nesting". Modal will popup
- Project and upload example materials list (public > examples > material_list.xlsx)

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
  spreadsheet import; a template with no business is shared with every business. Recording one is
  a live change, and needs no deploy
- The AI half needs OPENAI_API_KEY (and optionally OPENAI_MODEL) in .env. Without it the form is
  still filled in for any spreadsheet an existing template already matches. The sample's contents
  are sent to OpenAI when a key is set
