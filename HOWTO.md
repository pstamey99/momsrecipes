# Mom's Recipes — Pipeline How-To Guide

**Pipeline v2.0** · paulstamey.com/momsrecipes

---

## First-Time Setup

### 1. Install dependencies

```bash
pip3 install python-docx Pillow lxml requests --break-system-packages
```

### 2. Set up credentials

```bash
cd /Users/pstamey/momrecipes
cp pipeline_config.template.json pipeline_config.json
```

Edit `pipeline_config.json` with your actual credentials:

```json
{
  "ftp_user": "paul.stamey@paulstamey.com",
  "ftp_pass": "YOUR_FTP_PASSWORD",
  "api_key": "YOUR_API_KEY",
  "api_url": "https://paulstamey.com/momsrecipes/api/index.php"
}
```

> **Never share or commit `pipeline_config.json` — it contains your passwords.**

### 3. Add your recipe files

Place `.docx` files in the `input/` folder. Subdirectories become categories:

```
input/
├── Breads/
│   ├── Norwegian-Breads.docx
│   └── Quick-Breads.docx
├── Desserts/
│   ├── Cakes.docx
│   └── Pies.docx
├── Casseroles/
│   └── Main-Dishes.docx
└── Cookies/
    └── Holiday-Cookies.docx
```

### 4. Deploy everything

```bash
python3 recipe_pipeline.py --all
```

This processes your recipes, backs up the database, deploys the website, and pushes recipe data to the API.

---

## All Commands

| Command | What it does |
|---|---|
| `--process` | Extract recipes from Word docs, generate HTML, save metadata |
| `--deploy` | Full deploy: backup DB → upload frontend → upload API |
| `--deploy-frontend` | Upload only HTML/CSS/JS/images (skips api/ folder) |
| `--deploy-api` | Upload only api.php, .htaccess, helpers (never .db) |
| `--backup-db` | Download recipes.db from server to ./backups/ |
| `--update-db` | Push recipe data to the live database via API |
| `--update-users` | Upload approved_users.json to server |
| `--all` | Run everything: process → deploy → update-db |
| `--debug` | Show detailed error tracebacks (combine with any command) |

You can chain commands:

```bash
python3 recipe_pipeline.py --process --deploy-frontend
python3 recipe_pipeline.py --deploy-api --update-db
```

---

## Common Tasks

### Add new recipes

1. Add `.docx` files to `input/`
2. Run:

```bash
python3 recipe_pipeline.py --all
```

### Fix a typo on the website

If you edited HTML/CSS/JS locally and don't need to reprocess recipes:

```bash
python3 recipe_pipeline.py --deploy-frontend
```

### Update the API backend

When you have a new version of `api.php` or other PHP files:

```bash
python3 recipe_pipeline.py --deploy-api
```

### Test locally before deploying

```bash
python3 recipe_pipeline.py --process

cd output/website
python3 -m http.server 8000
```

Then open: http://localhost:8000/momsrecipes/index.html

### Back up the database

```bash
python3 recipe_pipeline.py --backup-db
```

Backups are saved to `./backups/` with timestamps. The pipeline keeps the 10 most recent and cleans up older ones automatically.

> Every deploy command (`--deploy`, `--deploy-frontend`, `--deploy-api`) automatically backs up the database first.

---

## Managing Approved Users

The recipe site only allows approved users to register and log in. The approved list lives in `approved_users.json`.

### Add a new user

1. Open `approved_users.json`:

```json
{
  "users": [
    "paul",
    "sarah",
    "margaret",
    "john",
    "admin",
    "cousin_lisa",
    "aunt_helen"
  ]
}
```

2. Add the username (lowercase), save the file.

3. Push it to the server:

```bash
python3 recipe_pipeline.py --update-users
```

That's it — no reprocessing or redeploying needed. The change takes effect immediately.

### Remove a user

Remove their name from `approved_users.json` and run `--update-users`.

### View current approved users

```bash
cat approved_users.json
```

Or run `--update-users` — it prints the list before uploading.

---

## What Gets Deployed Where

```
public_html/momsrecipes/
├── index.html              ← --deploy-frontend
├── approved_users.json     ← --update-users
├── momsrecipes/
│   ├── index.html          ← --deploy-frontend
│   ├── recipes.json        ← --deploy-frontend
│   ├── styles.css          ← --deploy-frontend
│   ├── script.js           ← --deploy-frontend
│   ├── auth.js             ← --deploy-frontend
│   └── recipes/
│       ├── {uuid}.html     ← --deploy-frontend
│       └── ...
├── images/                 ← --deploy-frontend
└── api/
    ├── api.php             ← --deploy-api
    ├── index.php           ← --deploy-api
    ├── .htaccess           ← --deploy-api
    ├── database.php        ← --deploy-api
    ├── helpers.php         ← --deploy-api
    └── data/
        └── recipes.db      ← NEVER deployed (auto-created by PHP)
```

### Safety rules

- **recipes.db is never uploaded.** It's auto-created by PHP on the server. This protects user accounts, recipe edits, and all live data from being overwritten.
- **These files are always excluded from upload:** `*.db`, `*.sqlite`, `pipeline_config.json`, `.DS_Store`, `*.pyc`, `*.log`
- **Database is backed up before every deploy** to `./backups/`

---

## Security

### Credentials

Passwords and API keys are stored in `pipeline_config.json`, not in the pipeline script. Keep this file private and out of version control.

### HTTPS

The site uses HTTPS via Namecheap's free Let's Encrypt certificate. All data between browser and server is encrypted.

### Passwords

The PHP backend hashes passwords with `password_hash()` (bcrypt). Passwords are never stored in plaintext.

### Approved users

Only users listed in `approved_users.json` can register. The list is checked both client-side (JavaScript) and server-side (PHP).

---

## Local Project Structure

```
/Users/pstamey/momrecipes/
├── recipe_pipeline.py              # Pipeline script
├── recipe_converter_namecheap.py   # Recipe parser + HTML generator
├── pipeline_config.json            # Your credentials (private)
├── pipeline_config.template.json   # Template (safe to share)
├── approved_users.json             # Approved user list
├── input/                          # Word documents go here
│   ├── Breads/
│   ├── Desserts/
│   └── ...
├── output/                         # Generated by --process
│   ├── recipes_metadata.json
│   └── website/
│       └── momsrecipes/
├── api/                            # API backend files
│   ├── api.php
│   ├── index.php
│   └── .htaccess
└── backups/                        # Auto-downloaded DB backups
    ├── recipes_20260213_143022.db
    └── ...
```

---

## Troubleshooting

### "Connection timed out" or "Login failed"

- Check `ftp_user` and `ftp_pass` in `pipeline_config.json`
- Namecheap FTP user format: `paul.stamey@paulstamey.com`
- Try `ping ftp.paulstamey.com` to check connectivity

### "550 Can't create directory"

The pipeline auto-detects Namecheap's FTP path. If it fails, check `ftp_remote_dir` in `pipeline_config.json`. Try `/public_html/momsrecipes`.

### Recipes not appearing after --update-db

- Check the API: https://paulstamey.com/momsrecipes/api/index.php/health
- Look at `output/failed_recipes.json` for errors
- Common cause: field name mismatch (`instructions` vs `directions`)
- Run `--deploy-api` first to update the backend, then `--update-db`

### "Recipe converter not found"

Make sure `recipe_converter_namecheap.py` is in the same folder as `recipe_pipeline.py`.

### Metadata buttons are gray on recipe pages

Run `--process` to regenerate HTML with metadata fields, then `--deploy-frontend`.

### Recipe page shows wrong title / broken link

Recipe pages use UUIDs as filenames, so title changes won't break links. If you see stale data, run `--process --deploy-frontend` to regenerate.

### Search filters lost when pressing Back

Filters are saved to URL query parameters. If this isn't working, run `--process --deploy-frontend` to get the updated `script.js`.

---

## Quick Reference

| I want to... | Run this |
|---|---|
| Deploy everything fresh | `--all` |
| Add new recipes | Add .docx to input/, then `--all` |
| Fix a website typo | `--deploy-frontend` |
| Update API code | `--deploy-api` |
| Add an approved user | Edit approved_users.json, then `--update-users` |
| Back up the database | `--backup-db` |
| Test locally | `--process`, then `python3 -m http.server 8000` |
| See what went wrong | Add `--debug` to any command |
| Check the live site | https://paulstamey.com/momsrecipes/ |
| Check the API | https://paulstamey.com/momsrecipes/api/index.php/health |
