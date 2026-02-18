**Mom's Recipes**

Deployment Pipeline v2.0

Complete Guide

paulstamey.com/momsrecipes

Last updated: February 12, 2026

**Quick Start**

Get up and running in 3 steps. This assumes you already have Python 3
and the project files on your Mac.

**1. Set Up Credentials**

Copy the config template and fill in your FTP password and API key:

+-----------------------------------------------------------------------+
| cd /Users/pstamey/momrecipes                                          |
|                                                                       |
| cp pipeline_config.template.json pipeline_config.json                 |
|                                                                       |
| \# Edit with your credentials:                                        |
|                                                                       |
| nano pipeline_config.json                                             |
+-----------------------------------------------------------------------+

Your pipeline_config.json should look like this:

+-----------------------------------------------------------------------+
| {                                                                     |
|                                                                       |
| \"ftp_user\": \"paul.stamey@paulstamey.com\",                         |
|                                                                       |
| \"ftp_pass\": \"YOUR_PASSWORD_HERE\",                                 |
|                                                                       |
| \"api_key\": \"YOUR_API_KEY_HERE\",                                   |
|                                                                       |
| \"api_url\": \"https://paulstamey.com/momsrecipes/api/index.php\"     |
|                                                                       |
| }                                                                     |
+-----------------------------------------------------------------------+

  -----------------------------------------------------------------------
  IMPORTANT: Never share pipeline_config.json or commit it to version
  control. It contains your passwords.

  -----------------------------------------------------------------------

**2. Process Your Recipes**

Place your Word documents in the input/ folder, then run:

  -----------------------------------------------------------------------
  python3 recipe_pipeline.py \--process

  -----------------------------------------------------------------------

This extracts all recipes from your .docx files, generates the HTML
website, and saves metadata for the database.

**3. Deploy Everything**

Push it all live with one command:

  -----------------------------------------------------------------------
  python3 recipe_pipeline.py \--all

  -----------------------------------------------------------------------

This runs: process recipes, backup database, deploy frontend, deploy
API, and update the database.

**All Pipeline Commands**

The pipeline supports targeted operations so you can control exactly
what gets deployed.

  ---------------------------- ------------------------------------------
  **Command**                  **What It Does**

  \--process                   Extract recipes from Word docs, generate
                               HTML website, save metadata

  \--deploy                    Full deploy: backup DB, then upload
                               frontend + API

  \--deploy-frontend           Upload only HTML/CSS/JS/images (skips api/
                               folder)

  \--deploy-api                Upload only api.php, .htaccess, helpers
                               (never .db files)

  \--backup-db                 Download recipes.db from server to
                               ./backups/

  \--update-db                 Push recipe data to the live database via
                               API

  \--all                       Run everything: process, deploy, update-db

  \--debug                     Show detailed error tracebacks (combine
                               with any command)
  ---------------------------- ------------------------------------------

**Combining Commands**

You can chain flags together for custom workflows:

+-----------------------------------------------------------------------+
| \# Process and deploy frontend only (don't touch API)                 |
|                                                                       |
| python3 recipe_pipeline.py \--process \--deploy-frontend              |
|                                                                       |
| \# Update API code and push new recipe data                           |
|                                                                       |
| python3 recipe_pipeline.py \--deploy-api \--update-db                 |
|                                                                       |
| \# Just backup the database                                           |
|                                                                       |
| python3 recipe_pipeline.py \--backup-db                               |
|                                                                       |
| \# Process recipes without deploying (for local testing)              |
|                                                                       |
| python3 recipe_pipeline.py \--process                                 |
+-----------------------------------------------------------------------+

**Typical Workflows**

**Adding New Recipes**

When you add new Word documents to the input/ folder:

+-----------------------------------------------------------------------+
| \# 1. Add .docx files to input/ folder                                |
|                                                                       |
| \# 2. Process and deploy everything                                   |
|                                                                       |
| python3 recipe_pipeline.py \--all                                     |
+-----------------------------------------------------------------------+

**Fixing a Typo on the Website**

If you only changed HTML/CSS/JS and don't need to re-process recipes:

+-----------------------------------------------------------------------+
| \# Deploy just the frontend files                                     |
|                                                                       |
| python3 recipe_pipeline.py \--deploy-frontend                         |
+-----------------------------------------------------------------------+

**Updating the API Backend**

When you have a new version of api.php or other backend files:

+-----------------------------------------------------------------------+
| \# Deploy just the API (auto-backs up DB first)                       |
|                                                                       |
| python3 recipe_pipeline.py \--deploy-api                              |
+-----------------------------------------------------------------------+

**Testing Locally Before Deploying**

Process recipes and preview locally without pushing to the server:

+-----------------------------------------------------------------------+
| \# 1. Process only                                                    |
|                                                                       |
| python3 recipe_pipeline.py \--process                                 |
|                                                                       |
| \# 2. Start local server                                              |
|                                                                       |
| cd output/website                                                     |
|                                                                       |
| python3 -m http.server 8000                                           |
|                                                                       |
| \# 3. Open in browser:                                                |
|                                                                       |
| \# http://localhost:8000/momsrecipes/index.html                       |
+-----------------------------------------------------------------------+

**Recovering from a Bad Deploy**

Every deploy automatically backs up your database. Backups are saved in
the ./backups/ folder with timestamps:

+-----------------------------------------------------------------------+
| ls ./backups/                                                         |
|                                                                       |
| \# recipes_20260212_143022.db                                         |
|                                                                       |
| \# recipes_20260211_091544.db                                         |
|                                                                       |
| \# \...                                                               |
+-----------------------------------------------------------------------+

To restore, upload the backup via FTP to
/public_html/momsrecipes/api/data/recipes.db

**What Gets Deployed Where**

The pipeline keeps your database safe by strictly separating what each
deploy command touches.

**Server Directory Structure**

+-----------------------------------------------------------------------+
| public_html/momsrecipes/                                              |
|                                                                       |
| ├── index.html ← \--deploy-frontend                                   |
|                                                                       |
| ├── momsrecipes/                                                      |
|                                                                       |
| │ ├── index.html ← \--deploy-frontend                                 |
|                                                                       |
| │ └── recipes/ ← \--deploy-frontend                                   |
|                                                                       |
| ├── images/ ← \--deploy-frontend                                      |
|                                                                       |
| └── api/                                                              |
|                                                                       |
| ├── api.php ← \--deploy-api                                           |
|                                                                       |
| ├── index.php ← \--deploy-api                                         |
|                                                                       |
| ├── .htaccess ← \--deploy-api                                         |
|                                                                       |
| ├── database.php ← \--deploy-api                                      |
|                                                                       |
| ├── helpers.php ← \--deploy-api                                       |
|                                                                       |
| └── data/                                                             |
|                                                                       |
| └── recipes.db ← NEVER deployed (auto-created by PHP)                 |
+-----------------------------------------------------------------------+

  -----------------------------------------------------------------------
  KEY SAFETY RULE: recipes.db is NEVER uploaded by the pipeline. It is
  auto-created by the PHP backend on the server the first time a request
  comes in. This protects your live data from being overwritten.

  -----------------------------------------------------------------------

**Deploy Exclusion List**

The following files are always blocked from upload, regardless of which
deploy command you use:

  ---------------------------- ------------------------------------------
  **Pattern**                  **Why**

  \*.db, \*.sqlite, \*.sqlite3 Database files --- never overwrite live
                               data

  pipeline_config.json         Contains your passwords

  .DS_Store, Thumbs.db         OS junk files

  \*.pyc, \_\_pycache\_\_      Python cache files

  \*.log                       Log files
  ---------------------------- ------------------------------------------

**Security**

**Credential Management**

Pipeline v2.0 loads credentials from pipeline_config.json instead of
hardcoding them in the script. This keeps passwords out of your code.

-   Create pipeline_config.json from the template (see Quick Start)

-   Never commit pipeline_config.json to Git or share it

-   Add it to your .gitignore file

-   The pipeline masks your password in terminal output

**HTTPS**

Your site uses HTTPS via Namecheap's free Let's Encrypt certificate.
This means all data between your browser and the server (including login
credentials and API calls) is encrypted in transit.

**Password Hashing**

Your PHP backend already uses password_hash() with bcrypt for user
passwords. Passwords are never stored in plaintext.

**Rate Limiting (Recommended)**

Consider adding rate limiting on login attempts to prevent brute force
attacks. This can be done in your api.php by tracking failed login
attempts per IP and temporarily blocking after 5 failures within 15
minutes.

**API Key**

The API key in pipeline_config.json authenticates your pipeline to the
backend. Keep it private. If compromised, generate a new one and update
both the server config and your local pipeline_config.json.

**Database Backups**

**Automatic Backups**

The pipeline automatically downloads your database before every deploy.
This happens whenever you run:

-   \--deploy (full deploy)

-   \--deploy-frontend

-   \--deploy-api

-   \--backup-db (standalone)

**Where Backups Are Stored**

+-----------------------------------------------------------------------+
| ./backups/                                                            |
|                                                                       |
| recipes_20260212_143022.db \# Feb 12, 2:30 PM                         |
|                                                                       |
| recipes_20260211_091544.db \# Feb 11, 9:15 AM                         |
|                                                                       |
| recipes_20260210_171233.db \# Feb 10, 5:12 PM                         |
+-----------------------------------------------------------------------+

The pipeline keeps the 10 most recent backups and automatically cleans
up older ones.

**Manual Backup**

You can trigger a backup at any time without deploying:

  -----------------------------------------------------------------------
  python3 recipe_pipeline.py \--backup-db

  -----------------------------------------------------------------------

**Restoring a Backup**

If something goes wrong, restore by uploading a backup file to the
server:

+-----------------------------------------------------------------------+
| \# Via FTP (FileZilla or command line):                               |
|                                                                       |
| \# Upload ./backups/recipes_20260212_143022.db                        |
|                                                                       |
| \# to /public_html/momsrecipes/api/data/recipes.db                    |
|                                                                       |
| \# Or via cPanel File Manager:                                        |
|                                                                       |
| \# Navigate to public_html/momsrecipes/api/data/                      |
|                                                                       |
| \# Upload and overwrite recipes.db                                    |
+-----------------------------------------------------------------------+

**Local Project Structure**

+-----------------------------------------------------------------------+
| /Users/pstamey/momrecipes/                                            |
|                                                                       |
| ├── recipe_pipeline.py \# This pipeline script                        |
|                                                                       |
| ├── recipe_converter_namecheap.py \# Recipe parser + HTML generator   |
|                                                                       |
| ├── pipeline_config.json \# YOUR credentials (not shared)             |
|                                                                       |
| ├── pipeline_config.template.json \# Template (safe to share)         |
|                                                                       |
| ├── input/ \# Your Word documents                                     |
|                                                                       |
| │ ├── Breads/                                                         |
|                                                                       |
| │ ├── Desserts/                                                       |
|                                                                       |
| │ ├── Casseroles/                                                     |
|                                                                       |
| │ └── \...                                                            |
|                                                                       |
| ├── output/ \# Generated by \--process                                |
|                                                                       |
| │ ├── recipes_metadata.json \# Recipe data for DB                     |
|                                                                       |
| │ └── website/ \# HTML files for deploy                               |
|                                                                       |
| ├── api/ \# API backend files                                         |
|                                                                       |
| │ ├── api.php                                                         |
|                                                                       |
| │ ├── index.php                                                       |
|                                                                       |
| │ ├── .htaccess                                                       |
|                                                                       |
| │ └── database.php                                                    |
|                                                                       |
| └── backups/ \# Auto-downloaded DB backups                            |
|                                                                       |
| ├── recipes_20260212_143022.db                                        |
|                                                                       |
| └── \...                                                              |
+-----------------------------------------------------------------------+

**Troubleshooting**

**FTP Connection Refused**

**Symptom:** \"Connection timed out\" or \"Login failed\"

-   Verify your ftp_user and ftp_pass in pipeline_config.json

-   Check that ftp.paulstamey.com is reachable (try ping)

-   Namecheap FTP user format: paul.stamey@paulstamey.com

**\"No such file or directory\" During Deploy**

**Symptom:** 550 error when creating directories

The pipeline auto-detects Namecheap's FTP path. If it still fails, check
ftp_remote_dir in pipeline_config.json. Try /public_html/momsrecipes
instead of /paul.stamey/public_html/momsrecipes.

**Recipes Not Appearing After \--update-db**

-   Check that the API is running: visit
    https://paulstamey.com/momsrecipes/api/index.php/health

-   Look at output/failed_recipes.json for specific errors

-   Common cause: \"Missing required field: directions\" means the DB
    schema expects a different field name

-   Run \--deploy-api first to update the backend, then \--update-db

**\"Recipe converter not found\"**

Make sure recipe_converter_namecheap.py is in the same directory as
recipe_pipeline.py. Also ensure dependencies are installed:

  -----------------------------------------------------------------------
  pip3 install python-docx Pillow lxml \--break-system-packages

  -----------------------------------------------------------------------

**HTML Generation Failed**

If you see \"HTML generation failed\" but processing succeeded, the
metadata is still saved. You can deploy the API data without the HTML:

  -----------------------------------------------------------------------
  python3 recipe_pipeline.py \--update-db

  -----------------------------------------------------------------------

Add \--debug to see the full error traceback and identify what went
wrong.

**Quick Reference Card**

Keep this handy for everyday use:

  ---------------------------- ---------------------------------------------------------
  **I want to\...**            **Run this**

  Deploy everything fresh      python3 recipe_pipeline.py \--all

  Add new recipes              Add .docx to input/, then \--all

  Fix a website typo           \--deploy-frontend

  Update API code              \--deploy-api

  Just backup the database     \--backup-db

  Test locally first           \--process, then python3 -m http.server 8000

  See what went wrong          Add \--debug to any command

  Check the live site          https://paulstamey.com/momsrecipes/

  Check the API health         https://paulstamey.com/momsrecipes/api/index.php/health
  ---------------------------- ---------------------------------------------------------
