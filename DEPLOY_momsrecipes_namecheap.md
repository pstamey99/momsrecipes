# Mom's Recipes — Deploy from Scratch on Namecheap

Complete guide to set up and deploy the Mom's Recipes site on a fresh Namecheap shared hosting account.

---

## Prerequisites

Install these on your local machine before starting:

```bash
pip3 install python-docx Pillow lxml requests
```

---

## 1. Local Folder Setup

Create your project folder and place files as follows:

```
momsrecipes/
├── recipe_pipeline.py                ← pipeline script
├── recipe_converter_namecheap.py     ← converter script
├── approved_users.json               ← approved usernames (create this)
│
├── input/                            ← PUT YOUR WORD DOCS HERE
│   └── *.docx
│
└── src/
    ├── frontend/
    │   ├── index.html                ← main search page
    │   ├── script.js
    │   ├── styles.css
    │   └── auth.js
    └── api/
        ├── index.php
        ├── database.php
        ├── config.php
        ├── helpers.php
        └── .htaccess
```

### Create approved_users.json

```json
{
  "users": ["paul", "alison", "sarah", "margaret", "john", "admin"]
}
```

Add or remove names as needed. These are the usernames allowed to register.

---

## 2. Namecheap cPanel Setup

### Create the momsrecipes folder

1. Log in to **cPanel → File Manager**
2. Navigate to `public_html/`
3. Create a new folder: `momsrecipes`
4. Inside `momsrecipes/`, create another folder: `api`
5. Inside `api/`, create another folder: `data`

The server structure should be:
```
public_html/
└── momsrecipes/
    └── api/
        └── data/          ← SQLite database lives here (auto-created)
```

### Get your FTP credentials

In cPanel go to **FTP Accounts** and note:
- FTP hostname (usually `ftp.paulstamey.com`)
- FTP username
- FTP password
- Remote path: `/public_html/momsrecipes`

---

## 3. First-Time Pipeline Setup

Run the setup wizard. It will ask for your FTP credentials and save them to `pipeline_config.json` (never commit this file):

```bash
cd momsrecipes/
python3 recipe_pipeline.py --setup
```

Enter when prompted:
- FTP host: `ftp.paulstamey.com`
- FTP username: *(your cPanel FTP username)*
- FTP password: *(your FTP password)*
- Remote path: `/public_html/momsrecipes`
- Site URL base path: `/momsrecipes`

### Verify the connection

```bash
python3 recipe_pipeline.py --check
```

You should see `✓ FTP connection successful`.

---

## 4. Process Your Word Documents

Place all `.docx` recipe files in the `input/` folder, then run:

```bash
python3 recipe_pipeline.py --process
```

This will:
- Parse all Word docs into structured recipes
- Extract images
- Generate individual HTML pages for each recipe (`output/recipes/<uuid>.html`)
- Generate the main `index.html` search page
- Save `output/recipes_metadata.json`

You'll see a summary like:
```
✓ Processed 291 recipes
✓ Generated 291 recipe pages
```

---

## 5. Build (Inject API URL + Cache-Bust)

```bash
python3 recipe_pipeline.py --build
```

This copies `src/` → `output/` and injects the correct absolute API URL (`https://paulstamey.com/momsrecipes/api/index.php`) into all HTML/JS files, and adds cache-busting timestamps to CSS/JS links.

---

## 6. Deploy API (PHP Files)

Upload the PHP backend to the server:

```bash
python3 recipe_pipeline.py --deploy-api
```

This uploads:
- `api/index.php`
- `api/database.php`
- `api/config.php`
- `api/helpers.php`
- `api/.htaccess`

The first time `index.php` runs, it will auto-create `api/data/recipes.db` (the SQLite database).

---

## 7. Deploy Frontend (HTML/CSS/JS)

Upload the frontend:

```bash
python3 recipe_pipeline.py --deploy-frontend
```

This uploads:
- `index.html`
- `styles.css`
- `script.js`
- `auth.js`
- Any other static assets

Does **not** upload `recipes/` or `images/` — those go via `--update-db`.

---

## 8. Sync Recipes to Database + Upload Pages

```bash
python3 recipe_pipeline.py --update-db
```

This:
1. Uploads all `recipes/*.html` pages via FTP
2. Uploads all `images/` via FTP
3. Calls the API to create/update each recipe in the SQLite database
4. Matches recipes by UUID so existing records are updated, not duplicated

You'll see progress like:
```
✓ Created 291 new recipes
✓ Updated 0 existing recipes
```

---

## 9. Add Approved Users

```bash
python3 recipe_pipeline.py --update-users
```

This uploads `approved_users.json` to the server and syncs all usernames into the database. You should see:

```
✓ Synced 6 users into database: paul, alison, sarah, margaret, john, admin
```

Family members can now register at `paulstamey.com/momsrecipes` using their approved username.

---

## 10. Verify the Site

Open a browser and check:

| URL | Should show |
|-----|-------------|
| `paulstamey.com/momsrecipes` | Login/Register page |
| After login | Recipe search grid with all recipes |
| Click any recipe | Individual recipe page |
| `paulstamey.com/momsrecipes/api/index.php?action=get_recipes_search` | JSON list of all recipes |

---

## Full From-Scratch Command Sequence

```bash
# 1. First time only — set up credentials
python3 recipe_pipeline.py --setup

# 2. Verify FTP connection
python3 recipe_pipeline.py --check

# 3. Process Word docs → HTML pages
python3 recipe_pipeline.py --process

# 4. Inject API URL into built files
python3 recipe_pipeline.py --build

# 5. Upload PHP backend
python3 recipe_pipeline.py --deploy-api

# 6. Upload HTML/CSS/JS frontend
python3 recipe_pipeline.py --deploy-frontend

# 7. Upload recipe pages + sync database
python3 recipe_pipeline.py --update-db

# 8. Sync approved users
python3 recipe_pipeline.py --update-users
```

Or run everything in one command (after `--setup` and `--check`):

```bash
python3 recipe_pipeline.py --all
```

---

## Day-to-Day Update Workflows

### Added or edited Word docs
```bash
python3 recipe_pipeline.py --process
python3 recipe_pipeline.py --update-db
```

### Changed frontend HTML/CSS/JS (in src/frontend/)
```bash
python3 recipe_pipeline.py --build
python3 recipe_pipeline.py --deploy-frontend
```

### Changed PHP API files (in src/api/)
```bash
python3 recipe_pipeline.py --deploy-api
```

### Added a new family member
1. Add their username to `approved_users.json`
2. Run:
```bash
python3 recipe_pipeline.py --update-users
```
They can now register at the site immediately.

### Backup the database
```bash
python3 recipe_pipeline.py --backup-db
```
Downloads `recipes.db` to `backups/recipes_TIMESTAMP.db`.

---

## Troubleshooting

### 403 Forbidden on API
The `.htaccess` in `api/` may be blocking requests. Run:
```bash
python3 recipe_pipeline.py --fix-server
```

### Recipe cards show "Array" instead of ingredients
This is a browser cache issue with old recipe HTML. Run:
```bash
python3 recipe_pipeline.py --process
python3 recipe_pipeline.py --update-db
```

### Registration denied — username not on approved list
The approved users table may be out of sync. Run:
```bash
python3 recipe_pipeline.py --update-users
```

### Recipes not updating after edits
The database may have stale data. Run `--update-db` to force a full sync.

### Changes not showing in browser
Hard refresh: `Cmd+Shift+R` (Mac) or `Ctrl+Shift+R` (Windows). The pipeline adds cache-busting timestamps on every `--build`.

---

## File Reference

| File | Purpose | Edit? |
|------|---------|-------|
| `pipeline_config.json` | FTP credentials | Never commit to git |
| `approved_users.json` | Approved usernames | Yes — add/remove users |
| `src/frontend/index.html` | Main search page template | Yes |
| `src/frontend/styles.css` | All site styles | Yes |
| `src/frontend/script.js` | Search/filter logic | Yes |
| `src/frontend/auth.js` | Login/register logic | Yes |
| `src/api/index.php` | API router | Yes |
| `src/api/database.php` | Database class | Yes |
| `src/api/config.php` | App settings | Yes |
| `src/api/helpers.php` | Utility functions | Yes |
| `output/` | Auto-generated — never edit directly | No |
| `backups/` | Database backups | No |

---

## Server File Structure (Namecheap)

```
public_html/
└── momsrecipes/
    ├── index.html
    ├── styles.css
    ├── script.js
    ├── auth.js
    ├── approved_users.json
    ├── recipes/
    │   └── <uuid>.html        ← one per recipe
    ├── images/
    │   └── *.jpg / *.png
    └── api/
        ├── index.php
        ├── database.php
        ├── config.php
        ├── helpers.php
        ├── .htaccess
        └── data/
            └── recipes.db     ← SQLite database (never overwrite)
```

> **Important:** The database (`api/data/recipes.db`) is never touched by any deploy command. It is only modified via API calls. Always run `--backup-db` before major changes.
