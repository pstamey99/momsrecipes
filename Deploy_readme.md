# Mom's Recipes - Complete Setup & Deployment Guide

**Complete documentation for building, deploying, and managing your family recipe website**

Version: 2.0  
Last Updated: February 2026

---

## 📑 Table of Contents

1. [Overview](#overview)
2. [System Architecture](#system-architecture)
3. [Prerequisites](#prerequisites)
4. [Initial Setup](#initial-setup)
5. [Generate Website](#generate-website)
6. [Deploy Backend API](#deploy-backend-api)
7. [Deploy Frontend Website](#deploy-frontend-website)
8. [Deploy Everything](#deploy-everything)
9. [Database Management](#database-management)
10. [Maintenance & Updates](#maintenance--updates)
11. [Troubleshooting](#troubleshooting)
12. [Quick Reference](#quick-reference)

---

## Overview

### What This System Does

**Mom's Recipes** is a complete recipe management system that:
- Converts Word documents into a searchable recipe website
- Provides a REST API for recipe management
- Creates beautiful HTML pages for each recipe
- Includes a database for storing and searching recipes
- Offers testing and import tools

### Components

1. **Backend API** - PHP-based REST API with SQLite database
2. **Frontend Website** - Static HTML/CSS/JS recipe browser
3. **Processing Pipeline** - Python scripts to convert Word docs to HTML
4. **Deployment System** - Automated FTP deployment to Namecheap

---

## System Architecture

### Directory Structure

```
momrecipes/                          # Project root
├── input/                           # Your Word documents (source)
│   ├── Breads-Pastries.docx
│   ├── Cakes-Frosting.docx
│   ├── Casseroles.docx
│   └── ...
├── api/                             # Backend API files
│   ├── config.php                   # API configuration
│   ├── database.php                 # Database schema (directions field)
│   ├── index.php                    # API endpoint
│   ├── helpers.php                  # Utility functions
│   ├── test.html                    # API testing interface
│   ├── import-tool.html             # Bulk import tool
│   └── .htaccess                    # Server configuration
├── output/                          # Generated files (created by --process)
│   ├── recipes_metadata.json        # Recipe data for database
│   └── website/                     # Complete HTML website
│       ├── index.html               # Landing page
│       ├── favicon.ico
│       └── momsrecipes/
│           ├── index.html           # Recipe browser
│           ├── style.css
│           ├── script.js
│           ├── recipes/             # Individual recipe pages
│           │   ├── lefse.html
│           │   ├── jule-kake.html
│           │   └── ...
│           └── images/              # Recipe images
│               └── *.jpg
├── recipe_pipeline.py               # Main processing script
├── recipe_converter_namecheap.py    # Recipe extraction engine
├── deploy.py                        # Deployment script
├── env_config.py                    # Environment configuration
├── .htaccess-momsrecipes           # Parent directory config
├── .htaccess-data                   # Database protection
└── README.md                        # This file
```

### Server Structure (After Deployment)

```
/public_html/                        # Web root
└── momsrecipes/                     # Your website
    ├── index.html                   # Landing page
    ├── favicon.ico
    ├── .htaccess                    # Parent config
    ├── api/                         # Backend API
    │   ├── config.php
    │   ├── database.php
    │   ├── index.php
    │   ├── helpers.php
    │   ├── test.html
    │   ├── import-tool.html
    │   ├── .htaccess
    │   └── data/
    │       ├── recipes.db           # SQLite database
    │       └── .htaccess            # Protects database
    └── momsrecipes/                 # Frontend website
        ├── index.html               # Recipe browser
        ├── style.css
        ├── script.js
        ├── recipes/                 # Recipe pages
        │   └── *.html
        └── images/                  # Recipe images
            └── *.jpg
```

### URLs

| What | URL |
|------|-----|
| Landing Page | https://paulstamey.com/momsrecipes/ |
| Recipe Browser | https://paulstamey.com/momsrecipes/momsrecipes/ |
| Individual Recipe | https://paulstamey.com/momsrecipes/momsrecipes/recipes/lefse.html |
| API Endpoint | https://paulstamey.com/momsrecipes/api/index.php |
| API Test Page | https://paulstamey.com/momsrecipes/api/test.html |

---

## Prerequisites

### Software Requirements

**On Your Mac:**
- Python 3.8 or higher
- pip (Python package manager)
- Git (optional, for version control)

**Check versions:**
```bash
python3 --version  # Should be 3.8+
pip3 --version
```

### Python Packages

**Install required packages:**
```bash
pip3 install python-docx Pillow pytesseract requests --break-system-packages
```

**Verify installation:**
```bash
python3 -c "import docx; import PIL; import requests; print('All packages installed!')"
```

### Server Requirements

**Namecheap Hosting:**
- cPanel access
- PHP 7.4 or higher
- SQLite3 support
- FTP access
- mod_rewrite enabled

**Check PHP version in cPanel:**
1. Log into cPanel
2. Find "Select PHP Version" or "MultiPHP Manager"
3. Ensure PHP 7.4+ is selected
4. Enable extensions: sqlite3, json, mbstring

### Access Credentials

**You'll need:**
- FTP hostname: `ftp.paulstamey.com`
- FTP username: `paul.stamey@paulstamey.com`
- FTP password: [your password]
- cPanel URL: `https://paulstamey.com:2083`

---

## Initial Setup

### Step 1: Download Project Files

**Download these files from our conversation:**
1. recipe_pipeline.py
2. recipe_converter_namecheap.py
3. deploy.py
4. env_config.py
5. All files in `api/` folder:
   - config.php
   - database.php
   - index.php
   - helpers.php
   - test.html
   - import-tool.html
   - .htaccess

### Step 2: Organize Files

**Create project directory:**
```bash
cd /Users/pstamey
mkdir -p momrecipes/input
mkdir -p momrecipes/api
cd momrecipes
```

**Place files:**
- Python scripts in `/Users/pstamey/momrecipes/`
- API files in `/Users/pstamey/momrecipes/api/`
- Word documents in `/Users/pstamey/momrecipes/input/`

### Step 3: Configure FTP Credentials

**Edit env_config.py:**
```bash
nano env_config.py
```

**Update these lines:**
```python
# Line 42: Add your FTP password
'pass': 'your-actual-ftp-password',

# Line 45: Verify environment (start with 'test')
CURRENT_ENVIRONMENT = 'test'
```

**Save:** `Ctrl+O`, `Enter`, `Ctrl+X`

### Step 4: Verify Configuration

**Check paths are correct:**
```bash
python3 env_config.py
```

**Should show:**
```
Current Environment: TEST
Description: Testing in production location - be careful!

FTP Paths:
  API: /public_html/momsrecipes/api
  Website: /public_html/momsrecipes

URLs:
  API: https://paulstamey.com/momsrecipes/api/index.php
  Website: https://paulstamey.com/momsrecipes
  Test Page: https://paulstamey.com/momsrecipes/api/test.html
```

### Step 5: Add Your Recipes

**Copy Word documents:**
```bash
# Copy your recipe Word documents to input/
cp ~/Documents/recipes/*.docx input/

# Verify they're there
ls -la input/
```

**Supported formats:**
- .docx (Word 2007+)
- Can contain text and images
- Recipes should follow a consistent format

---

## Generate Website

### What This Does

**The `--process` command:**
- Reads all Word documents from `input/`
- Extracts recipes, ingredients, directions, images
- Generates HTML pages for each recipe
- Creates searchable recipe browser
- Saves metadata for database

**Generated files:**
- `output/recipes_metadata.json` - Recipe data for database
- `output/website/` - Complete HTML website

### Full Website Generation

**Run processing:**
```bash
cd /Users/pstamey/momrecipes

# Clean previous output
rm -rf output/

# Process all recipes
python3 recipe_pipeline.py --process
```

**Expected output:**
```
╔═══════════════════════════════════════════════════════════════════╗
║              Recipe Processing Pipeline v2.0                      ║
╚═══════════════════════════════════════════════════════════════════╝

ℹ Processing recipes from: ./input
ℹ Found 15 recipe files to process

Processing: Breads-Pastries.docx
  ✓ Extracted 18 recipes
  ✓ Generated HTML pages

Processing: Cakes-Frosting.docx
  ✓ Extracted 24 recipes
  ✓ Generated HTML pages

...

ℹ Generating HTML website files...
✓ Website generated at: output/website
ℹ   Main page: output/website/index.html
ℹ   Recipes page: output/website/momsrecipes/index.html

✓ Saved recipe metadata to: output/recipes_metadata.json
✓ Total recipes extracted: 248 from 15 files

════════════════════════════════════════════════════════════════════
Processing Complete!
════════════════════════════════════════════════════════════════════

Next steps:
  1. Test locally: cd output/website && python3 -m http.server 8000
  2. Deploy backend: python3 deploy.py --backend
  3. Deploy frontend: python3 deploy.py --frontend
  4. Populate database: python3 recipe_pipeline.py --update-db
```

### Verify Generation

**Check output files:**
```bash
# Verify metadata file exists
ls -lh output/recipes_metadata.json

# Check website files
ls output/website/

# Count recipe pages
ls output/website/momsrecipes/recipes/*.html | wc -l

# Check images
ls output/website/momsrecipes/images/*.jpg | wc -l
```

### Test Locally

**Run local server:**
```bash
cd output/website
python3 -m http.server 8000
```

**Open in browser:**
```bash
open http://localhost:8000/momsrecipes/
```

**Test:**
- Landing page loads
- Recipe browser shows all recipes
- Search works
- Individual recipes load
- Images display

**Stop server:** `Ctrl+C`

### Regenerate After Changes

**When to regenerate:**
- Added new Word documents
- Edited existing Word documents
- Changed recipe content
- Want to update website

**How to regenerate:**
```bash
# Clean output
rm -rf output/

# Regenerate everything
python3 recipe_pipeline.py --process

# Test locally
cd output/website
python3 -m http.server 8000
open http://localhost:8000/momsrecipes/

# If good, deploy
cd ../..
python3 deploy.py --frontend
python3 recipe_pipeline.py --update-db
```

---

## Deploy Backend API

### What This Does

**The `--backend` command:**
- Uploads PHP files to `/public_html/momsrecipes/api/`
- Creates directory structure
- Sets up .htaccess configuration
- Protects database directory
- Creates empty database (schema only)

**Deployed files:**
- config.php
- database.php (with 'directions' field)
- index.php
- helpers.php
- test.html
- import-tool.html
- .htaccess files

### Deploy Backend

**Run backend deployment:**
```bash
cd /Users/pstamey/momrecipes

python3 deploy.py --backend
```

**Expected output:**
```
╔═══════════════════════════════════════════════════════════════════╗
║          Unified Deployment System v1.0                          ║
╚═══════════════════════════════════════════════════════════════════╝

Current Environment: TEST
Description: Testing in production location - be careful!

FTP Paths:
  API: /public_html/momsrecipes/api
  Website: /public_html/momsrecipes

======================================================================
Deploying Backend to TEST
======================================================================

ℹ Connecting to ftp.paulstamey.com...
✓ Connected to ftp.paulstamey.com
ℹ Creating directory: /public_html/momsrecipes/api
✓   → Created: /public_html
✓   → Created: /public_html/momsrecipes
✓   → Created: /public_html/momsrecipes/api
✓ In directory: /public_html/momsrecipes/api
✓ Created data/ directory

ℹ Uploading backend files...
ℹ   Uploading config.php...
✓     → Uploaded config.php
ℹ   Uploading database.php...
✓     → Uploaded database.php
ℹ   Uploading index.php...
✓     → Uploaded index.php
ℹ   Uploading helpers.php...
✓     → Uploaded helpers.php
ℹ   Uploading test.html...
✓     → Uploaded test.html
ℹ   Uploading import-tool.html...
✓     → Uploaded import-tool.html
ℹ   Uploading .htaccess...
✓     → Uploaded .htaccess

ℹ Uploading data directory protection...
✓   → Protected data/ directory
ℹ Uploading parent directory .htaccess...
✓   → Configured parent directory

✓ Backend deployed! Uploaded 8 files

ℹ Backend URLs:
  API Endpoint: https://paulstamey.com/momsrecipes/api/index.php
  Test Page: https://paulstamey.com/momsrecipes/api/test.html

======================================================================
Deployment Complete!
======================================================================
```

### Verify Backend Deployment

**Test API health:**
```bash
curl https://paulstamey.com/momsrecipes/api/index.php
```

**Should return:**
```json
{"status":"ok","database":"connected"}
```

**Open test interface:**
```bash
open https://paulstamey.com/momsrecipes/api/test.html
```

**Test in browser:**
1. Page loads with purple gradient header
2. "Test Connection" button works
3. Shows green checkmark: "API Status: OK"
4. Database: "Connected"

### Troubleshoot Backend

**If 404 error:**
```bash
# Check files via cPanel File Manager
# Navigate to: /public_html/momsrecipes/api/
# Verify files exist: index.php, database.php, etc.

# Check .htaccess is uploaded
# Should see .htaccess in api/ directory

# Verify PHP is enabled in cPanel
# Check "Select PHP Version" or "MultiPHP Manager"
```

**If "Missing required field: instructions" error:**
```bash
# Old database.php is cached
# Delete database via cPanel:
# Navigate to: /public_html/momsrecipes/api/data/
# Delete: recipes.db

# Redeploy backend
python3 deploy.py --backend

# Test again
curl https://paulstamey.com/momsrecipes/api/index.php
```

**If FTP connection fails:**
```bash
# Check credentials in env_config.py
nano env_config.py

# Verify:
'user': 'paul.stamey@paulstamey.com',  # Correct format
'pass': 'your-actual-password',        # Not placeholder

# Test FTP manually
ftp ftp.paulstamey.com
# Login with credentials
# If works: credentials are correct
```

---

## Deploy Frontend Website

### What This Does

**The `--frontend` command:**
- Uploads HTML files from `output/website/` to server
- Creates directory structure
- Uploads recipe pages
- Uploads images
- Uploads CSS and JavaScript

**Deployed files:**
- Landing page (index.html)
- Recipe browser (momsrecipes/index.html)
- Individual recipes (momsrecipes/recipes/*.html)
- Images (momsrecipes/images/*.jpg)
- CSS, JavaScript

### Prerequisites

**Must run `--process` first:**
```bash
# Generate website files
python3 recipe_pipeline.py --process

# Verify output exists
ls output/website/momsrecipes/index.html
```

If `output/website/` doesn't exist, see [Generate Website](#generate-website) section.

### Deploy Frontend

**Run frontend deployment:**
```bash
cd /Users/pstamey/momrecipes

python3 deploy.py --frontend
```

**Expected output:**
```
======================================================================
Deploying Frontend to TEST
======================================================================

ℹ Connecting to ftp.paulstamey.com...
✓ Connected to ftp.paulstamey.com
ℹ Creating directory: /public_html/momsrecipes
✓ In directory: /public_html/momsrecipes

ℹ Uploading website files...
ℹ     Uploaded 20 files...
ℹ     Uploaded 40 files...
ℹ     Uploaded 60 files...
...
ℹ     Uploaded 260 files...

✓ Frontend deployed! Uploaded 268 files

ℹ Frontend URLs:
  Main Site: https://paulstamey.com/momsrecipes/
  Recipe Browser: https://paulstamey.com/momsrecipes/momsrecipes/

======================================================================
Deployment Complete!
======================================================================
```

### Verify Frontend Deployment

**Open landing page:**
```bash
open https://paulstamey.com/momsrecipes/
```

**Should see:**
- Hero section with "Mom's Recipes"
- Description text
- "Browse Recipes" button

**Open recipe browser:**
```bash
open https://paulstamey.com/momsrecipes/momsrecipes/
```

**Should see:**
- Search bar at top
- Filter options (Category, Tags)
- Grid of recipe cards
- Recipe count

**Test functionality:**
- Click a recipe card → Opens recipe page
- Search for "bread" → Filters recipes
- Check that images load
- Click "Back to Recipes" → Returns to browser

### Troubleshoot Frontend

**If 404 on landing page:**
```bash
# Check files uploaded via cPanel
# Navigate to: /public_html/momsrecipes/
# Should see: index.html, favicon.ico, momsrecipes/

# If missing, redeploy
python3 deploy.py --frontend
```

**If recipe browser is empty:**
```bash
# Database is empty - recipes haven't been uploaded
# Run database population:
python3 recipe_pipeline.py --update-db
```

**If images don't load:**
```bash
# Check images uploaded
# Via cPanel: /public_html/momsrecipes/momsrecipes/images/
# Should see .jpg files

# If missing, regenerate and redeploy
python3 recipe_pipeline.py --process
python3 deploy.py --frontend
```

**If search doesn't work:**
```bash
# Database not populated
python3 recipe_pipeline.py --update-db

# Then refresh page
```

---

## Deploy Everything

### What This Does

**The `--full` command:**
- Deploys backend API (`--backend`)
- Deploys frontend website (`--frontend`)
- Verifies deployment
- Shows all URLs

**Does NOT populate database** - you still need to run `--update-db`

### Full Deployment

**Run complete deployment:**
```bash
cd /Users/pstamey/momrecipes

python3 deploy.py --full
```

**Expected output:**
```
╔═══════════════════════════════════════════════════════════════════╗
║          Unified Deployment System v1.0                          ║
╚═══════════════════════════════════════════════════════════════════╝

Current Environment: TEST
Description: Testing in production location - be careful!

======================================================================
Deploying Backend to TEST
======================================================================
[... backend deployment output ...]
✓ Backend deployed! Uploaded 8 files

======================================================================
Deploying Frontend to TEST
======================================================================
[... frontend deployment output ...]
✓ Frontend deployed! Uploaded 268 files

======================================================================
Verifying Deployment
======================================================================
ℹ Testing API at: https://paulstamey.com/momsrecipes/api/index.php
✓ API is responding!
ℹ   Status: ok
ℹ   Database: connected

======================================================================
Deployment Complete!
======================================================================
✓ Deployed to: TEST

ℹ Access your deployment:
  Website: https://paulstamey.com/momsrecipes/
  API: https://paulstamey.com/momsrecipes/api/index.php
  Test Page: https://paulstamey.com/momsrecipes/api/test.html
```

### Complete Workflow

**From scratch to live:**
```bash
# 1. Generate website files
python3 recipe_pipeline.py --process

# 2. Deploy everything
python3 deploy.py --full

# 3. Populate database
python3 recipe_pipeline.py --update-db

# 4. Verify
open https://paulstamey.com/momsrecipes/
```

### Alternative: All-in-One Command

**Use pipeline's --all flag:**
```bash
python3 recipe_pipeline.py --all
```

**This runs:**
1. `--process` - Generate website
2. `--deploy` - Deploy backend and frontend
3. `--update-db` - Populate database

**One command, complete deployment!** ✅

---

## Database Management

### Database Overview

**Two parts:**

1. **Schema** (structure) - Defined in `database.php`
   - Table structure
   - Field definitions (including 'directions')
   - Automatically created by API

2. **Data** (content) - Your recipes
   - Uploaded via `--update-db`
   - Stored in `recipes.db`

### Populate Database

**Upload recipes to database:**
```bash
cd /Users/pstamey/momrecipes

# Must run --process first to generate metadata
python3 recipe_pipeline.py --process

# Upload to database
python3 recipe_pipeline.py --update-db
```

**Expected output:**
```
╔═══════════════════════════════════════════════════════════════════╗
║           Recipe Database Update v2.0                            ║
╚═══════════════════════════════════════════════════════════════════╝

ℹ Loading recipes from: output/recipes_metadata.json
ℹ Found 248 recipes to upload

ℹ Uploading to: https://paulstamey.com/momsrecipes/api/index.php

Processing recipes...
✓ Created: Lefse (ID: 1)
✓ Created: Jule Kake (ID: 2)
✓ Created: Krumkake (ID: 3)
...
✓ Created: Aunt Jane's Apple Pie (ID: 248)

══════════════════════════════════════════════════════════════════════
Database Update Complete!
══════════════════════════════════════════════════════════════════════

✓ Successfully uploaded: 248 recipes
✗ Failed uploads: 0

View your recipes:
  Website: https://paulstamey.com/momsrecipes/momsrecipes/
  API: https://paulstamey.com/momsrecipes/api/index.php/recipes
```

### Verify Database

**Check recipe count:**
```bash
curl https://paulstamey.com/momsrecipes/api/index.php/recipes | python3 -c "import json,sys; data=json.load(sys.stdin); print(f'Total recipes: {len(data[\"recipes\"])}')"
```

**Via test.html:**
```bash
open https://paulstamey.com/momsrecipes/api/test.html

# Click "Get All Recipes"
# Should show all recipes
```

**Via website:**
```bash
open https://paulstamey.com/momsrecipes/momsrecipes/

# Recipe count should match
# All recipes should appear
```

### Reset Database

**Delete and repopulate:**

**Via cPanel:**
1. Log into cPanel File Manager
2. Navigate to: `/public_html/momsrecipes/api/data/`
3. Delete: `recipes.db`
4. Database will recreate automatically on next API call

**Then repopulate:**
```bash
python3 recipe_pipeline.py --update-db
```

### Update Recipes

**After editing Word documents:**
```bash
# 1. Regenerate metadata
python3 recipe_pipeline.py --process

# 2. Delete old database
# Via cPanel: delete recipes.db

# 3. Repopulate
python3 recipe_pipeline.py --update-db

# 4. Redeploy frontend (for updated HTML)
python3 deploy.py --frontend
```

---

## Maintenance & Updates

### Add New Recipes

**Complete workflow:**
```bash
# 1. Add new Word document to input/
cp ~/Documents/new-recipes.docx input/

# 2. Regenerate website
python3 recipe_pipeline.py --process

# 3. Deploy frontend (updates HTML)
python3 deploy.py --frontend

# 4. Add to database
python3 recipe_pipeline.py --update-db
```

**Time:** ~2-5 minutes

### Edit Existing Recipes

**Complete workflow:**
```bash
# 1. Edit Word document in input/

# 2. Delete old database (to avoid duplicates)
# Via cPanel: delete /public_html/momsrecipes/api/data/recipes.db

# 3. Regenerate everything
python3 recipe_pipeline.py --process

# 4. Deploy frontend
python3 deploy.py --frontend

# 5. Repopulate database
python3 recipe_pipeline.py --update-db
```

### Update API Code

**If you modify PHP files:**
```bash
# Just redeploy backend
python3 deploy.py --backend

# Test
curl https://paulstamey.com/momsrecipes/api/index.php
```

**No need to regenerate or redeploy frontend**

### Update Website Styling

**If you modify HTML/CSS templates:**
```bash
# 1. Edit template in recipe_converter_namecheap.py

# 2. Regenerate HTML
python3 recipe_pipeline.py --process

# 3. Test locally
cd output/website
python3 -m http.server 8000
open http://localhost:8000/momsrecipes/

# 4. If good, deploy
cd ../..
python3 deploy.py --frontend
```

### Backup Your Work

**Backup recipes:**
```bash
# Backup Word documents
cp -r input/ ../backup-input-$(date +%Y%m%d)/

# Backup generated files
cp -r output/ ../backup-output-$(date +%Y%m%d)/
```

**Download database:**
Via cPanel:
1. Navigate to: `/public_html/momsrecipes/api/data/`
2. Right-click `recipes.db`
3. Download to your Mac

### Check for Issues

**Run diagnostic commands:**
```bash
# Check local files
ls -lh input/*.docx
ls -lh output/recipes_metadata.json
ls output/website/momsrecipes/recipes/*.html | wc -l

# Check server
curl https://paulstamey.com/momsrecipes/api/index.php
curl https://paulstamey.com/momsrecipes/api/index.php/recipes | python3 -c "import json,sys; data=json.load(sys.stdin); print(f'Recipes: {len(data[\"recipes\"])}')"

# Open test page
open https://paulstamey.com/momsrecipes/api/test.html
```

---

## Troubleshooting

### Common Issues & Solutions

#### Issue: "Website directory not found"

**Error:**
```
✗ Website directory not found: output/website
ℹ Run: python3 recipe_pipeline.py --process
```

**Solution:**
```bash
# Generate website files first
python3 recipe_pipeline.py --process

# Then deploy
python3 deploy.py --frontend
```

---

#### Issue: "Missing required field: instructions"

**Error:**
```
{"error": "Missing required field: instructions"}
```

**Cause:** Old database schema still has 'instructions' instead of 'directions'

**Solution:**
```bash
# 1. Delete old database via cPanel
# Navigate to: /public_html/momsrecipes/api/data/
# Delete: recipes.db

# 2. Redeploy backend
python3 deploy.py --backend

# 3. Test
curl -X POST https://paulstamey.com/momsrecipes/api/index.php/recipes \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","ingredients":"Test","directions":"Test","category":"Test"}'

# Should return success
```

---

#### Issue: "404 Not Found" on API

**Error:**
```
404 Not Found
The resource requested could not be found on this server!
```

**Solution:**
```bash
# Check files exist via cPanel
# Navigate to: /public_html/momsrecipes/api/
# Should see: index.php, database.php, .htaccess

# If files missing, redeploy
python3 deploy.py --backend

# Check .htaccess uploaded
# Should see .htaccess in api/ directory

# If still 404, check PHP is enabled
# cPanel → Select PHP Version → Verify 7.4+
```

---

#### Issue: Empty Recipe Browser

**Symptoms:**
- Website loads but shows "No recipes found"
- Recipe count is 0

**Solution:**
```bash
# Database is empty - populate it
python3 recipe_pipeline.py --process
python3 recipe_pipeline.py --update-db

# Verify
curl https://paulstamey.com/momsrecipes/api/index.php/recipes | python3 -c "import json,sys; print(len(json.load(sys.stdin)['recipes']))"

# Refresh website
open https://paulstamey.com/momsrecipes/momsrecipes/
```

---

#### Issue: Images Don't Display

**Solution:**
```bash
# Check images were uploaded
# Via cPanel: /public_html/momsrecipes/momsrecipes/images/
# Should see .jpg files

# If missing, regenerate and redeploy
python3 recipe_pipeline.py --process
python3 deploy.py --frontend

# Check image paths in HTML
# Open a recipe page
# Right-click broken image → Inspect
# Check src path
```

---

#### Issue: FTP Connection Failed

**Error:**
```
✗ Backend deployment failed: 421 Home directory not available - aborting
```

**Solution:**
```bash
# Check credentials
nano env_config.py

# Verify these are correct:
'user': 'paul.stamey@paulstamey.com',  # Include @paulstamey.com
'pass': 'your-actual-password',        # Not placeholder

# Test FTP manually
ftp ftp.paulstamey.com
# Login with credentials

# If works: update env_config.py
# If fails: reset password in cPanel → FTP Accounts
```

---

#### Issue: Search Doesn't Work

**Symptoms:**
- Search bar appears but doesn't filter
- No results when typing

**Solution:**
```bash
# Frontend needs to connect to API
# Check API is working
curl https://paulstamey.com/momsrecipes/api/index.php/recipes

# Should return recipe array

# Check browser console for errors
# Open website → F12 → Console tab
# Look for CORS or connection errors

# If CORS error, check .htaccess has:
# Header set Access-Control-Allow-Origin "*"
```

---

### Get Help

**Diagnostic information to gather:**

```bash
# System info
python3 --version
pip3 list | grep -E "docx|Pillow|requests"

# Local files
ls -la input/
ls -la output/
ls -la api/

# Test local generation
python3 recipe_pipeline.py --process

# Test API
curl https://paulstamey.com/momsrecipes/api/index.php
curl https://paulstamey.com/momsrecipes/api/index.php/recipes

# Check FTP
python3 env_config.py

# Browser console (F12)
# Copy any error messages
```

---

## Quick Reference

### Essential Commands

```bash
# Generate website from Word docs
python3 recipe_pipeline.py --process

# Deploy backend API
python3 deploy.py --backend

# Deploy frontend website
python3 deploy.py --frontend

# Deploy everything
python3 deploy.py --full

# Populate database
python3 recipe_pipeline.py --update-db

# Complete workflow (all-in-one)
python3 recipe_pipeline.py --all

# Test locally
cd output/website && python3 -m http.server 8000

# Check environment
python3 env_config.py
```

### URLs

```bash
# Website
https://paulstamey.com/momsrecipes/                    # Landing page
https://paulstamey.com/momsrecipes/momsrecipes/        # Recipe browser

# API
https://paulstamey.com/momsrecipes/api/index.php       # API endpoint
https://paulstamey.com/momsrecipes/api/test.html       # Test interface

# Test commands
curl https://paulstamey.com/momsrecipes/api/index.php  # Health check
curl https://paulstamey.com/momsrecipes/api/index.php/recipes  # Get all recipes
```

### File Locations

```bash
# Local
/Users/pstamey/momrecipes/input/                 # Your Word documents
/Users/pstamey/momrecipes/output/                # Generated files
/Users/pstamey/momrecipes/api/                   # Backend PHP files

# Server
/public_html/momsrecipes/api/                    # Backend API
/public_html/momsrecipes/momsrecipes/            # Frontend website
/public_html/momsrecipes/api/data/recipes.db     # Database
```

### Workflow Cheat Sheet

```bash
# First Time Setup
1. python3 recipe_pipeline.py --process
2. python3 deploy.py --full
3. python3 recipe_pipeline.py --update-db

# Add New Recipes
1. Copy .docx to input/
2. python3 recipe_pipeline.py --process
3. python3 deploy.py --frontend
4. python3 recipe_pipeline.py --update-db

# Update Existing Recipes
1. Edit .docx in input/
2. Delete recipes.db via cPanel
3. python3 recipe_pipeline.py --process
4. python3 deploy.py --frontend
5. python3 recipe_pipeline.py --update-db

# Fix API Issues
1. Edit api/*.php files
2. python3 deploy.py --backend
3. Test: curl https://paulstamey.com/momsrecipes/api/index.php

# Reset Everything
1. Delete /public_html/momsrecipes/ via cPanel
2. rm -rf output/
3. python3 recipe_pipeline.py --all
```

### Test Checklist

```bash
# Local Testing
□ python3 recipe_pipeline.py --process runs without errors
□ output/website/momsrecipes/index.html exists
□ Local server shows recipes: python3 -m http.server 8000
□ Images display locally

# Backend Testing
□ API health: curl https://paulstamey.com/momsrecipes/api/index.php
□ Returns: {"status":"ok","database":"connected"}
□ Test page loads: https://paulstamey.com/momsrecipes/api/test.html
□ Can create test recipe in test.html

# Frontend Testing
□ Landing page: https://paulstamey.com/momsrecipes/
□ Recipe browser: https://paulstamey.com/momsrecipes/momsrecipes/
□ Search works
□ Recipe pages load
□ Images display
□ Recipe count is correct

# Database Testing
□ Recipes populated: curl .../api/index.php/recipes
□ Count matches Word docs
□ Search returns results
```

---

## Support

### Resources

- **Complete Deployment Guide:** COMPLETE_DEPLOYMENT_GUIDE.md
- **.htaccess Guide:** HTACCESS_DEPLOYMENT.md
- **Environment Config:** env_config.py documentation
- **Troubleshooting:** This README § Troubleshooting

### Contact

For issues with:
- **Server/Hosting:** Namecheap Support (live chat)
- **FTP/cPanel:** Namecheap Support
- **PHP/Database:** Check cPanel logs and test.html

### Version History

- **v2.0** (Feb 2026) - Standardized on 'directions' field, simplified paths
- **v1.5** (Feb 2026) - Added multi-environment deployment
- **v1.0** (Feb 2026) - Initial release

---

## Summary

**You now have complete documentation for:**
- ✅ Generating website from Word documents
- ✅ Deploying backend API
- ✅ Deploying frontend website
- ✅ Managing database
- ✅ Maintaining and updating
- ✅ Troubleshooting issues

**Your workflow:**
1. Edit Word documents in `input/`
2. Run `python3 recipe_pipeline.py --process`
3. Run `python3 deploy.py --full`
4. Run `python3 recipe_pipeline.py --update-db`
5. Visit https://paulstamey.com/momsrecipes/

**Questions?** Review the relevant section above or check troubleshooting!

---

**Happy Recipe Managing! 🍳**
# momsrecipes
