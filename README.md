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
   .\deploy.ps1 -ResetDb   # create tables, load the Tolkien data, fetch images, copy to C:\XAMPP\htdocs\librescape
   .\deploy.ps1            # later: copy changed files only
   ```

4. Open http://localhost/librescape/.

`php tools\check_sources.php` lists entries that have no source yet.

## Layout

| Path | Contents |
|------|----------|
| `index.php`, `assets/` | Page, JavaScript, CSS, bundled vis-timeline |
| `api/` | JSON endpoints: `timeline.php`, `item.php`, `search.php` |
| `lib/db.php` | Database helpers |
| `sql/` | Schema and seed data |
| `tools/` | Image fetcher and source checker |
| `models/` | MySQL Workbench model |
| `notes/` | Session notes |
