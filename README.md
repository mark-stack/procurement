
## Steelnesting.com.au

### Getting started:
- Create database called "procurement"
- php artisan migrate --seed
- composer run dev (starts the server, queue worker, log tail and Vite together — leave it running)
- Login as admin (mark.laravel.coder@gmail.com, Password123#)
- Need import master material list from Excel. Nav > dropdown > "Update Materials (Admin)". Allow 10 seconds then click away from 'done' incomplete modal

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
