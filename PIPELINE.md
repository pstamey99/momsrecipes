# Mom's Recipes — Pipeline Guide

## Overview

`recipe_pipeline.py` is the single script that handles everything:
processing Word docs → building output → deploying to Namecheap → updating the database.

---

## Local Folder Structure

Set this up next to `recipe_pipeline.py`:

```
momrecipes/
├── recipe_pipeline.py          ← the pipeline script
├── recipe_converter_namecheap.py
├── pipeline_config.json        ← your credentials (never deploy this)
├── src/                        ← MASTER SOURCE FILES — always edit here
│   ├── frontend/
│   │   ├── index.html          ← main recipe browser page
│   │   ├── script.js           ← search/filter/recipe grid
│   │   ├── styles.css          ← all site styles
│   │   └── auth.js             ← login / registration
│   ├── api/
│   │   ├── index.php           ← main API endpoint
│   │   ├── database.php        ← all database methods
│   │   ├── config.php          ← DB path and settings
│   │   ├── helpers.php         ← sendResponse() and utilities
│   │   ├── import.php          ← bulk recipe import tool
│   │   └── .htaccess           ← routing rules
│   ├── recipes/                ← place generated recipe HTML pages here
│   └── images/                 ← recipe images extracted from Word docs
├── input/                      ← Word documents (.docx) go here
├── output/                     ← AUTO-GENERATED — do not edit directly
│   └── website/
│       ├── frontend/           ← built from src/frontend/ with API URL injected
│       ├── api/                ← copied from src/api/
│       ├── recipes/            ← copied from src/recipes/
│       └── images/             ← copied from src/images/
└── backups/                    ← auto-downloaded database backups
```

---

## First-Time Setup

### 1. Install dependencies
```bash
pip3 install python-docx Pillow lxml requests
```

### 2. Run interactive setup
```bash
python3 recipe_pipeline.py --setup
```
This creates `pipeline_config.json` with your FTP credentials. You will be prompted for:
- FTP host (e.g. `ftp.paulstamey.com`)
- FTP username and password
- Remote path (e.g. `/public_html/momsrecipes`)
- Site URL base path (e.g. `/momsrecipes`)
- Local master source folder (default: `./src`)

### 3. Verify connection
```bash
python3 recipe_pipeline.py --check
```

### 4. Populate src/ with your master files
Copy your current working files into the `src/` folder:
- `src/frontend/` — index.html, script.js, styles.css, auth.js
- `src/api/` — index.php, database.php, config.php, helpers.php, .htaccess

---

## pipeline_config.json

Stored locally only — never uploaded to the server.

```json
{
    "ftp_host": "ftp.paulstamey.com",
    "ftp_user": "your@email.com",
    "ftp_pass": "yourpassword",
    "ftp_remote_dir": "/public_html/momsrecipes",
    "site_base_path": "/momsrecipes",
    "src_dir": "./src",
    "api_url": "https://paulstamey.com/momsrecipes/api/index.php"
}
```

---

## Commands

| Command | What it does |
|---|---|
| `--setup` | Interactive credential setup, creates pipeline_config.json |
| `--check` | Verify FTP connection and show remote directory contents |
| `--process` | Parse Word docs from input/, generate recipe HTML pages |
| `--build` | Copy src/ → output/, inject absolute API URL, cache-bust assets |
| `--deploy` | Full deploy: backup DB → frontend → API |
| `--deploy-frontend` | Deploy only HTML/CSS/JS/images (skips API folder) |
| `--deploy-api` | Deploy only API PHP files (skips DB files) |
| `--backup-db` | Download recipes.db from server to backups/ |
| `--update-db` | Push recipe metadata to live database via API |
| `--update-users` | Upload approved_users.json to server |
| `--all` | process → build → deploy → update-db |
| `--debug` | Show full tracebacks on errors |

Commands can be combined:
```bash
python3 recipe_pipeline.py --build --deploy-frontend
python3 recipe_pipeline.py --deploy-api --update-db
python3 recipe_pipeline.py --process --build --deploy
```

---

## Common Workflows

### Editing frontend files (index.html, script.js, styles.css)
```bash
# 1. Edit the file in src/frontend/
# 2. Build and deploy
python3 recipe_pipeline.py --build --deploy-frontend
```

### Editing API files (index.php, database.php, etc.)
```bash
# 1. Edit the file in src/api/
# 2. Deploy API only (no build needed for PHP files)
python3 recipe_pipeline.py --deploy-api
```

### Adding new recipes from Word documents
```bash
# 1. Put .docx files in input/
python3 recipe_pipeline.py --process   # generates recipe HTML pages
python3 recipe_pipeline.py --deploy-frontend   # uploads recipe pages
python3 recipe_pipeline.py --update-db         # pushes to database
```

### Full fresh deployment
```bash
python3 recipe_pipeline.py --all
# Equivalent to: --process --build --deploy --update-db
```

### Backup database only
```bash
python3 recipe_pipeline.py --backup-db
# Saves to backups/recipes_YYYYMMDD_HHMMSS.db
```

---

## What the --build Step Does

The build step is what makes the src/ master folder work correctly:

1. Copies `src/frontend/`, `src/api/`, `src/recipes/` → `output/website/`
2. Injects the correct absolute API URL into `index.html`:
   ```javascript
   // Before (in src/):
   const API_URL = 'api/index.php';
   // After (in output/):
   const API_URL = '/momsrecipes/api/index.php';
   ```
3. Injects `API_BASE` into `script.js` the same way
4. Adds cache-busting timestamps to script and CSS tags:
   ```html
   <script src="script.js?v=20260225143022"></script>
   ```

This means you never manually update API paths — just edit `src/` and run `--build`.

---

## Protected Files (Never Overwritten by Deploy)

These server files are always skipped during any deploy:

- `api/recipes.db` — live database containing all recipes, edits, and user accounts
- `api/logs/` — server log files
- Any `*.db`, `*.sqlite` files

The database is only touched by `--backup-db` (download) and `--update-db` (push metadata via API).

---

## Server File Structure

```
public_html/
└── momsrecipes/
    ├── index.html              ← main recipe browser
    ├── styles.css
    ├── script.js
    ├── auth.js
    ├── images/                 ← recipe images extracted from Word docs
    │   ├── lefse.jpg
    │   ├── krumkake.jpg
    │   └── ...
    ├── recipes/                ← individual recipe HTML pages
    │   ├── 1-egg-cake.html
    │   ├── lefse.html
    │   └── ...
    └── api/
        ├── index.php           ← API endpoint
        ├── database.php
        ├── config.php
        ├── helpers.php
        ├── import.php
        ├── .htaccess
        └── recipes.db          ← NEVER overwritten by deploy
```

---

## API Quick Reference

Test these URLs in your browser to verify the server is working:

| URL | Expected result |
|---|---|
| `/momsrecipes/api/index.php?action=get_recipes_search` | Array of all recipes |
| `/momsrecipes/api/index.php?action=get_blog` | Array of blog posts |
| `/momsrecipes/api/index.php?action=get_title_changes` | Array of title changes |
| `/momsrecipes/api/index.php?action=get_recipe&id=1` | Single recipe by ID |

---

## Troubleshooting

**404 on API calls**
- Check the API URL in browser DevTools Network tab
- Look for double `momsrecipes/momsrecipes/` in the path — run `--build` to fix
- Verify `index.php` exists on server in the `api/` folder

**FTP connection fails**
- Run `--check` to test connection
- Verify credentials in `pipeline_config.json`
- Try passive mode — already enabled by default in the script

**Recipes not showing on site**
- The database may be empty — run `--update-db` to push recipe metadata
- Check `/momsrecipes/api/index.php?action=get_recipes_search` returns data

**Browser showing old version after deploy**
- Run `--build` before deploying — it adds cache-busting timestamps
- Hard refresh with Ctrl+Shift+R (Cmd+Shift+R on Mac)

**Database missing after deploy**
- This is expected — the DB is never deployed, it lives on the server
- PHP auto-creates a fresh `recipes.db` on first API call
- Run `--update-db` to populate it with your recipes
