# Librescape

A timeline viewer for an author's life, works and publications against world events.
PHP + MariaDB backend, plain JavaScript frontend with [vis-timeline](https://visjs.github.io/vis-timeline/).
Sample data: J.R.R. Tolkien.

The full specification is in [Description.md](Description.md); notes on the data model are in `data_representation/`.

## Features

- Lanes for biography, works, publications and world events; nested periods open into bands as you zoom in.
- Detail panel with image, description, related entries, links and sources.
- Every entry cites at least one source (book, newspaper or Wikipedia article).
- Keyword lanes: pick a keyword to see all tagged entries in an extra lane.
- Freely licensed pictures from Wikipedia, stored locally with credit lines.

## Setup (Windows / XAMPP)

1. Create a MariaDB database and user, e.g. `librescape` / `librescape`.
2. Copy `config.example.php` to `config.php` and enter the database settings.
3. In PowerShell, from the project folder:

   ```powershell
   .\deploy.ps1 -ResetDb                       # create tables, load the Tolkien demo data, fetch images, deploy
   php tools\create_user.php alice admin "Alice"   # first administrator (asks for a password)
   ```

4. Open http://localhost/librescape/ (timeline) and http://localhost/librescape/admin/ (data entry).

## The database is the master copy

Once people enter data in the admin app, the database holds the real data:

- `.\deploy.ps1` copies changed files and applies pending schema migrations from `sql/migrations/`.
  Change the schema only by adding a new migration file, never by editing `sql/01_schema.sql` on a live system.
- `.\deploy.ps1 -ResetDb` **wipes everything** (entries, users, change log) and reloads the demo data.
  It asks for confirmation and writes a backup first.
- Backups: in the admin app (Backups, administrators only) or `php tools\backup.php`.
  They are stored outside the web folder (`backup_dir` in `config.php`).

## Admin app

- **Editors** create, change and delete events, periods, works, publications and people, with keywords, links, sources and relations.
  A *work* is a text (The Hobbit, On Fairy-Stories); a *publication* is a book, collection or journal issue that contains works.
  A work can appear in several publications (first printing, reprints, collections). Publications are not drawn on the timeline;
  they appear in the details of their works. (In the database, works are the table `publication` and publications the table `edition`.)
  Dropdowns search existing entries as you type; “+ Create …” adds a new person, source, period etc. without leaving the form.
  Dates are typed as you know them: `21 Sep 1937`, `Sep 1937`, `Summer 1937`, `Winter 1942/43`, `1937`, `1930s`, `c. 1930`.
- **Administrators** also manage users, backups and maintenance mode (the public timeline is offline, editors are locked out).
- Every change is recorded in the change log (who, when, old and new values).
- `php tools\check_sources.php` lists entries that have no source yet (the dashboard shows them too).

## Layout

| Path | Contents |
|------|----------|
| `index.php`, `assets/` | Timeline page, JavaScript, CSS, bundled vis-timeline |
| `api/` | Viewer JSON endpoints: `timeline.php`, `item.php`, `search.php` |
| `admin/` | Data-entry app: pages, `admin/api/` (lookup, quick-create, date preview), `admin/lib/` |
| `lib/` | Shared PHP: database helpers, entry helpers, backups |
| `sql/` | Baseline schema, demo data, `migrations/` |
| `tools/` | Migrations, backups, user creation, image fetcher, source checker |
| `models/` | MySQL Workbench model |
| `notes/` | Session notes |
