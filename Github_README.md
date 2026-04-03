# Mom's Recipes 🍳

A family recipe preservation project — digitizing and modernizing a collection of handwritten and typed recipes spanning multiple generations. Includes a Python processing pipeline, PHP REST API backend, and responsive HTML/CSS/JS frontend hosted on Namecheap shared hosting.

**Live site:** https://paulstamey.com/momsrecipes/

---

## Overview

Word documents containing family recipes are processed into a fully functional website with search, filtering, inline editing, user authentication, and image support. The collection includes 300+ recipes with strong Norwegian and Scandinavian heritage.

### Tech Stack

| Layer | Technology |
|---|---|
| Recipe processing | Python 3, python-docx, Pillow |
| Backend API | PHP 8, SQLite (via PDO) |
| Frontend | Vanilla HTML/CSS/JavaScript |
| Hosting | Namecheap shared hosting (FTP deploy) |
| Deployment | Python FTP automation (`recipe_pipeline.py`) |

---

## Project Structure

```
momrecipes/
├── recipe_pipeline.py          # Main deployment pipeline (run this)
├── recipe_converter_namecheap.py  # Word doc → HTML + metadata processor
├── pipeline_config.json        # Your credentials (never commit this)
│
├── input/                      # Place Word docs here
│   ├── CakesFrostings/
│   │   └── cakes.docx
│   ├── Breads/
│   └── ...
│
├── src/                        # Master source files — edit here, not output/
│   ├── frontend/
│   │   ├── index.html
│   │   ├── script.js
│   │   ├── styles.css
│   │   └── auth.js
│   └── api/
│       ├── index.php
│       ├── database.php
│       ├── helpers.php
│       ├── config.php
│       └── .htaccess
│
├── output/                     # Built files — generated, do not edit
│   ├── recipes_metadata.json   # All recipe data extracted from Word docs
│   └── website/
│       ├── momsrecipes/
│       │   ├── index.html
│       │   ├── script.js
│       │   ├── styles.css
│       │   ├── recipes/
│       │   │   └── <uuid>.html   # One page per recipe
│       │   └── images/
│       │       └── RecipeName-1.jpg
│       └── api/
│           ├── index.php
│           ├── database.php
│           ├── helpers.php
│           └── config.php
│
└── backups/                    # Auto-created DB backups before each deploy
```

### Server Structure (Namecheap)

```
public_html/
└── momsrecipes/
    ├── index.html              ← --deploy-frontend
    ├── script.js               ← --deploy-frontend
    ├── styles.css              ← --deploy-frontend
    ├── auth.js                 ← --deploy-frontend
    ├── recipes/
    │   └── <uuid>.html         ← --update-db (FTP)
    ├── images/
    │   └── RecipeName.jpg      ← --update-db (FTP)
    └── api/
        ├── index.php           ← --deploy-api
        ├── database.php        ← --deploy-api
        ├── helpers.php         ← --deploy-api
        ├── config.php          ← --deploy-api
        └── data/
            └── recipes.db      ← written by PHP only, NEVER via FTP
```

> **Note:** On Namecheap, FTP root is `/` (your home directory). The path `/public_html/momsrecipes` via FTP is `/home/paul.stamey/public_html/momsrecipes` on disk. cPanel File Manager shows the full path; FTP uses the chrooted path. Both point to the same folder.

---

## First-Time Setup

### 1. Install Python dependencies

```bash
pip3 install python-docx Pillow lxml requests
```

### 2. Configure credentials

```bash
python3 recipe_pipeline.py --setup
```

This creates `pipeline_config.json` in the project directory. **Do not commit this file.**

Or create it manually:

```json
{
  "ftp_user": "paul.stamey@paulstamey.com",
  "ftp_pass": "your-ftp-password",
  "ftp_remote_dir": "/paul.stamey/public_html/momsrecipes",
  "prod_dir": "/public_html/momsrecipes",
  "api_url": "https://paulstamey.com/momsrecipes/api/index.php"
}
```

> `ftp_remote_dir` is your **staging** path. `prod_dir` is your **production** path. If you only have one environment, set both to the same value.

### 3. Verify connection

```bash
python3 recipe_pipeline.py --check
```

### 4. Deploy the API (first time only)

```bash
python3 recipe_pipeline.py --deploy-api
```

The SQLite database (`recipes.db`) is auto-created by PHP on first request — you never upload it.

---

## Pipeline Commands

### Full workflow

```bash
python3 recipe_pipeline.py --all
```

Runs: `--process` → `--build` → `--deploy` → `--update-db`

### Individual commands

| Command | What it does | Deploys via |
|---|---|---|
| `--setup` | Interactive credential setup | — |
| `--check` | Verify FTP connection and show remote structure | FTP |
| `--process` | Parse Word docs → generate HTML + `recipes_metadata.json` | Local only |
| `--build` | Copy `src/` → `output/`, inject API URL + cache-bust timestamps | Local only |
| `--deploy` | Full deploy: backup + frontend + API | FTP |
| `--deploy-frontend` | Code only: `index.html`, CSS, JS, favicon (no `recipes/` or `images/`) | FTP |
| `--deploy-api` | PHP files only: `index.php`, `database.php`, `helpers.php`, `config.php` | FTP |
| `--update-db` | **Part A:** Sync recipe records to SQLite via HTTP API<br>**Part B:** Upload `recipes/` HTML pages + `images/` via FTP | HTTP + FTP |
| `--backup-db` | Download `recipes.db` from server to `backups/` | FTP |
| `--update-users` | Upload `approved_users.json` to server | FTP |
| `--fix-server` | Delete bad `.htaccess` from `api/` (fixes 403 errors) | FTP |
| `--promote` | Copy staging → production (preserves production DB) | FTP |
| `--all` | `--process` → `--build` → `--deploy` → `--update-db` | All |

### Common workflows

```bash
# First deploy (new server)
python3 recipe_pipeline.py --deploy-api
python3 recipe_pipeline.py --process
python3 recipe_pipeline.py --deploy-frontend
python3 recipe_pipeline.py --update-db

# After editing a Word doc
python3 recipe_pipeline.py --process --update-db

# After editing CSS/JS in src/
python3 recipe_pipeline.py --build --deploy-frontend

# After editing PHP in src/api/
python3 recipe_pipeline.py --deploy-api

# Promote staging to production
python3 recipe_pipeline.py --promote
```

---

## What Each Deploy Command Touches

| Command | Destination on server |
|---|---|
| `--deploy-frontend` | `public_html/momsrecipes/` (HTML/CSS/JS only) |
| `--deploy-api` | `public_html/momsrecipes/api/` (PHP files only) |
| `--update-db` (Part A) | `public_html/momsrecipes/api/data/recipes.db` (via HTTP POST) |
| `--update-db` (Part B) | `public_html/momsrecipes/recipes/` and `public_html/momsrecipes/images/` |
| `--promote` | Copies all of `ftp_remote_dir` → `prod_dir`, skips `api/data/` |

**The database is never uploaded via FTP.** It is written exclusively by PHP via HTTP API calls.

---

## Staging vs Production

The pipeline supports two environments configured in `pipeline_config.json`:

```json
{
  "ftp_remote_dir": "/paul.stamey/public_html/momsrecipes",
  "prod_dir": "/public_html/momsrecipes"
}
```

- All `--deploy-*` and `--update-db` commands target **staging** (`ftp_remote_dir`)
- `--promote` copies staging → production (`prod_dir`), skipping `api/data/` to preserve the live database

```bash
# Full staging → production workflow
python3 recipe_pipeline.py --process     # build locally
python3 recipe_pipeline.py --update-db   # push to staging
# ... test on staging ...
python3 recipe_pipeline.py --promote     # promote to production
```

---

## Input: Word Document Format

Place `.docx` files in `input/`. Subdirectory names become recipe categories:

```
input/
├── CakesFrostings/cakes.docx
├── Breads/breads.docx
├── Salads/salads.docx
└── Norwegian/lefse.docx
```

The converter (`recipe_converter_namecheap.py`) extracts:
- Title, ingredients, directions, notes
- Contributor / family source
- Servings, prep time, cook time
- Embedded images
- Metadata tags: `meal_type`, `cuisine`, `main_ingredient`, `method`, `occasion`
- A permanent UUID for stable URLs (even if the title changes)

---

## API Endpoints

All endpoints are on `https://paulstamey.com/momsrecipes/api/index.php`.

### Actions (GET query string)

| Action | Method | Description |
|---|---|---|
| `?action=get_recipes_search` | GET | All recipes with metadata for search index |
| `?action=get_recipe&id=<id_or_uuid>` | GET | Single recipe (accepts integer id or UUID string) |
| `?action=create_recipe` | POST | Create a recipe |
| `?action=update_recipe&id=<id_or_uuid>` | POST | Update a recipe |
| `?action=save_recipe&id=<id_or_uuid>` | POST | Save edits from recipe page |
| `?action=get_history&id=<id_or_uuid>` | GET | Edit history for a recipe |
| `?action=add_history&id=<id_or_uuid>` | POST | Add edit history entry |
| `?action=get_title_changes` | GET | All title change records |
| `?action=add_title_change` | POST | Record a title change |
| `?action=register` | POST | Register a new user |
| `?action=login` | POST | Login, returns session token |

### REST-style routes

| Route | Method | Description |
|---|---|---|
| `/recipes` | GET | List all recipes |
| `/recipes/<id>` | GET | Get single recipe |
| `/recipes` | POST | Create recipe |
| `/recipes/<id>` | PUT | Update recipe |
| `/recipes/<id>` | DELETE | Delete recipe |
| `/categories` | GET | All categories |
| `/contributors` | GET | All contributors |
| `/stats` | GET | Recipe counts and stats |

> Recipe IDs can be passed as either the integer database `id` or the UUID string embedded in each recipe page. The API resolves both automatically.

---

## Database Schema

SQLite database at `api/data/recipes.db`. Auto-created by PHP on first request.

**recipes table**

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | Auto-increment |
| `title` | TEXT | Recipe name |
| `category` | TEXT | From input subfolder name |
| `contributor` | TEXT | Family source / contributor |
| `servings` | TEXT | |
| `prep_time` | TEXT | |
| `cook_time` | TEXT | |
| `total_time` | TEXT | |
| `ingredients` | TEXT | Newline-separated |
| `directions` | TEXT | Newline-separated |
| `notes` | TEXT | |
| `tags` | TEXT | Comma-separated |
| `meal_type` | TEXT | Metadata tag |
| `cuisine` | TEXT | Metadata tag |
| `main_ingredient` | TEXT | Metadata tag |
| `method` | TEXT | Metadata tag |
| `occasion` | TEXT | Metadata tag |
| `uuid` | TEXT | Permanent identifier, used in HTML filenames |
| `image_data` | TEXT | Base64 encoded |
| `image_filename` | TEXT | |
| `source_url` | TEXT | |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | Auto-updated |

Additional tables: `edit_history`, `title_changes`, `users`, `sessions`, `approved_users`, `custom_meta_options`, `blog_posts`.

---

## Recipe Page URLs

Recipe HTML pages are named `<uuid>.html` for permanent, stable URLs:

```
https://paulstamey.com/momsrecipes/recipes/edfff9c1-ecff-4c4c-96e5-e3b44bcd8566.html
```

The UUID is assigned at processing time and stored in both the HTML file and the database. Title changes do not affect the URL.

---

## Safety Features

- **Database never overwritten via FTP** — `recipes.db` is excluded from all FTP uploads by the deploy exclusion list
- **Auto-backup before every deploy** — `--deploy`, `--deploy-frontend`, `--deploy-api` all trigger `--backup-db` first
- **Staging/production separation** — all deploy commands target staging; `--promote` requires typing `yes` to confirm
- **`--promote` preserves production DB** — `api/data/` is always skipped when promoting
- **Image data size guard** — `image_data` payloads over 4MB are stripped server-side to prevent PHP 500 errors
- **`pipeline_config.json` excluded** — credentials never uploaded to server

---

## Troubleshooting

### 403 Forbidden on API calls

A bad `.htaccess` is blocking requests:

```bash
python3 recipe_pipeline.py --fix-server
```

### 404 on recipe cards

Run `--update-db` to populate the `uuid` column. The search page builds card URLs from the UUID; if it's null the URL will be broken.

### 500 on `--update-db`

Usually an oversized image payload. The pipeline now strips `image_data` from update calls — images are deployed separately via FTP. Check `output/failed_recipes.json` for details.

### Recipe page title not updating

The recipe page fetches its title from `?action=get_recipe&id=<uuid>`. If the API returns 400, the UUID isn't in the database yet — run `--update-db` to backfill.

### Recipes load on search page but cards 404

UUID column is null for existing records. Run `--update-db` — it will update all existing records with their UUIDs.

### PHP display_errors causing 404

Namecheap returns a 404 HTML page when PHP outputs anything before headers. `display_errors` must be `0` in `index.php`. The current `src/api/index.php` has this set correctly and uses `ob_start()` output buffering as a secondary guard.

---

## pipeline_config.json Reference

```json
{
  "ftp_user": "paul.stamey@paulstamey.com",
  "ftp_pass": "your-ftp-password",
  "ftp_host": "ftp.paulstamey.com",
  "ftp_remote_dir": "/paul.stamey/public_html/momsrecipes",
  "prod_dir": "/public_html/momsrecipes",
  "api_url": "https://paulstamey.com/momsrecipes/api/index.php",
  "site_base_path": "/momsrecipes",
  "api_key": ""
}
```

| Key | Description |
|---|---|
| `ftp_user` | Namecheap FTP username (usually `user@domain.com`) |
| `ftp_pass` | FTP password |
| `ftp_host` | FTP hostname (default `ftp.paulstamey.com`) |
| `ftp_remote_dir` | Staging path on server |
| `prod_dir` | Production path on server (used by `--promote`) |
| `api_url` | Full URL to `index.php` on staging |
| `site_base_path` | URL path prefix, used by `--build` to inject `API_URL` |
| `api_key` | Optional API key (currently unused) |

---

## Requirements

- Python 3.8+
- `python-docx` — Word document parsing
- `Pillow` — image extraction and processing
- `lxml` — XML/HTML processing
- `requests` — HTTP API calls
- PHP 8.0+ with PDO SQLite (provided by Namecheap shared hosting)
