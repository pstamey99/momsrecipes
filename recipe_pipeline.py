#!/usr/bin/env python3
"""
Recipe Converter with Deployment Pipeline v3.0
Complete workflow: Process → Build → Deploy → Update Database

New in v3.0:
  - Master source folder (src/) — edit files here, never in output/
  - --build step injects correct absolute API URL + cache-busts assets
  - --setup for interactive credential configuration
  - --check to verify FTP connection and remote structure

New in v2.0:
  - Separate frontend/backend deploys (--deploy-frontend, --deploy-api)
  - Automatic database backup before any deploy
  - Deploy exclusion list (never uploads .db, .sqlite, config files)
  - External config file support (pipeline_config.json)
  - Security improvements (no hardcoded credentials)

Usage:
    python3 recipe_pipeline.py --setup              # First-time credential setup
    python3 recipe_pipeline.py --check              # Verify FTP connection
    python3 recipe_pipeline.py --process            # Process recipes from Word docs
    python3 recipe_pipeline.py --build              # Inject API URL + cache-bust into src/ → output/
    python3 recipe_pipeline.py --deploy             # Deploy BOTH frontend + API
    python3 recipe_pipeline.py --deploy-frontend    # Deploy only HTML/CSS/JS/images
    python3 recipe_pipeline.py --deploy-api         # Deploy only api.php + .htaccess
    python3 recipe_pipeline.py --backup-db          # Download database backup only
    python3 recipe_pipeline.py --update-db          # Update API database with recipes
    python3 recipe_pipeline.py --all                # Process → Build → Deploy → Update DB

Master Source Folder (src/):
    src/frontend/index.html   ← edit this, never output/
    src/frontend/script.js
    src/frontend/styles.css
    src/frontend/auth.js
    src/api/index.php
    src/api/database.php
    src/api/helpers.php
    src/api/.htaccess
    src/recipes/              ← generated recipe pages go here
"""

import os
import sys
import json
import io
import argparse
import ftplib
import fnmatch
import requests
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
from pathlib import Path
from datetime import datetime

# =============================================================================
# CONFIGURATION
# =============================================================================

CONFIG = {
    'input_dir': './input',
    'output_dir': './output',
    'images_dir': './output/images',
    'src_dir': './src',                # Master source folder — edit files here
    'site_base_path': '/momsrecipes', # Used to build absolute API URL in --build
    'ftp_host': 'ftp.paulstamey.com',
    'ftp_user': '',
    'ftp_pass': '',
    'ftp_remote_dir': '/public_html/momsrecipes',
    'prod_dir':       '',   # e.g. /public_html/momsrecipes  — set in pipeline_config.json
    'api_url': 'https://paulstamey.com/momsrecipes/api/index.php',
    'api_key': '',
    'backup_dir': './backups',
    'deploy_exclude': [
        '*.db', '*.sqlite', '*.sqlite3', 'recipes.db',
        'pipeline_config.json', '.DS_Store', 'Thumbs.db',
        '*.pyc', '__pycache__', '*.log',
    ],
    'api_files': [
        'api.php', 'index.php', '.htaccess',
        'database.php', 'helpers.php', 'import.php',
    ],
}

def load_config():
    """Load credentials from pipeline_config.json (kept outside version control)."""
    config_file = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pipeline_config.json')
    if os.path.exists(config_file):
        try:
            with open(config_file, 'r') as f:
                user_config = json.load(f)
            for key, value in user_config.items():
                if key in CONFIG:
                    CONFIG[key] = value
            # Rebuild api_url from site_base_path if not explicitly set
            if 'api_url' not in user_config and 'site_base_path' in user_config:
                CONFIG['api_url'] = f"https://paulstamey.com{CONFIG['site_base_path']}/api/index.php"
            masked = CONFIG['ftp_pass'][:2] + '***' if CONFIG['ftp_pass'] else '(empty)'
            print_info(f"Loaded config from: {config_file}")
            print_info(f"  FTP user: {CONFIG['ftp_user']}  |  FTP pass: {masked}")
            return True
        except Exception as e:
            print_error(f"Failed to load config file: {e}")
            return False
    else:
        if CONFIG['ftp_user'] and CONFIG['ftp_pass']:
            print_warning("Using hardcoded credentials — consider pipeline_config.json instead")
            return True
        print_error(f"Config file not found: {config_file}")
        print_info("Create pipeline_config.json:")
        print(json.dumps({"ftp_user": "you@domain.com", "ftp_pass": "xxx", "api_key": "xxx"}, indent=2))
        return False

def is_excluded(filename):
    """Check if a file should be excluded from deployment."""
    for pattern in CONFIG['deploy_exclude']:
        if fnmatch.fnmatch(filename, pattern) or fnmatch.fnmatch(filename.lower(), pattern.lower()):
            return True
    return False

# =============================================================================
# COLORS
# =============================================================================

class Colors:
    HEADER = '\033[95m'; OKBLUE = '\033[94m'; OKCYAN = '\033[96m'
    OKGREEN = '\033[92m'; WARNING = '\033[93m'; FAIL = '\033[91m'
    ENDC = '\033[0m'; BOLD = '\033[1m'

def print_header(msg):
    print(f"\n{Colors.HEADER}{Colors.BOLD}{'=' * 70}{Colors.ENDC}")
    print(f"{Colors.HEADER}{Colors.BOLD}{msg.center(70)}{Colors.ENDC}")
    print(f"{Colors.HEADER}{Colors.BOLD}{'=' * 70}{Colors.ENDC}\n")

def print_success(msg): print(f"{Colors.OKGREEN}✓ {msg}{Colors.ENDC}")
def print_error(msg):   print(f"{Colors.FAIL}✗ {msg}{Colors.ENDC}")
def print_warning(msg):  print(f"{Colors.WARNING}⚠ {msg}{Colors.ENDC}")
def print_info(msg):     print(f"{Colors.OKCYAN}ℹ {msg}{Colors.ENDC}")

# =============================================================================
# STEP 1: PROCESS RECIPES
# =============================================================================

def setup_credentials():
    """Interactive first-time setup — writes pipeline_config.json."""
    print_header("First-Time Setup")
    print_info("This creates pipeline_config.json with your FTP credentials.")
    print_info("That file is never deployed to the server.\n")

    config = {}
    config['ftp_host']       = input("  FTP host [ftp.paulstamey.com]: ").strip() or 'ftp.paulstamey.com'
    config['ftp_user']       = input("  FTP username: ").strip()
    config['ftp_pass']       = input("  FTP password: ").strip()
    config['ftp_remote_dir'] = input("  Remote path [/public_html/momsrecipes]: ").strip() or '/public_html/momsrecipes'
    config['site_base_path'] = input("  Site URL base path [/momsrecipes]: ").strip() or '/momsrecipes'
    config['src_dir']        = input("  Master source folder [./src]: ").strip() or './src'
    config['api_url']        = f"https://paulstamey.com{config['site_base_path']}/api/index.php"

    config_file = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pipeline_config.json')
    with open(config_file, 'w') as f:
        json.dump(config, f, indent=2)

    print_success(f"Saved: {config_file}")
    print()
    print_info("Next steps:")
    print_info("  1. Place master files in src/frontend/ and src/api/")
    print_info("  2. Run: python3 recipe_pipeline.py --build")
    print_info("  3. Run: python3 recipe_pipeline.py --deploy")


def check_connection():
    """Verify FTP connection and show remote directory contents."""
    print_header("Checking FTP Connection")
    if not load_config():
        print_error("Run --setup first")
        return False
    try:
        ftp, remote_dir = ftp_connect()
        print_success(f"Remote path: {remote_dir}")
        try:
            files = ftp.nlst()
            print_info(f"Contents: {', '.join(files[:15]) if files else '(empty)'}")
        except:
            pass
        ftp.quit()
        print_success("Connection OK")
        return True
    except Exception as e:
        print_error(f"Connection failed: {e}")
        return False


def build_output(local=False):
    """
    Copy src/ → output/, injecting the correct API URL and cache-busting assets.

    Modes:
      --build          Production build for Namecheap.
                         - Absolute API URL (/momsrecipes/api/index.php)
                         - Auth overlay ON (auth.js loaded, main-content hidden)
                         - Cache-busting version timestamps on all assets

      --build --local  Local dev build for python -m http.server.
                         - Relative API URL (api/index.php)
                         - Auth overlay BYPASSED (main-content shown immediately)
                         - No cache-busting (makes iteration faster)
                         - Output goes to output/website/frontend-local/
                         NOTE: PHP does not run locally — falls back to recipes.json

    Expects in src/:
      frontend/index.html, script.js, styles.css, auth.js
      api/index.php, config.php, database.php, helpers.php, .htaccess
      recipes/  (optional — generated recipe pages)
    """
    import re
    import shutil

    mode_label = "LOCAL (auth off, relative URL)" if local else "PRODUCTION (auth on, absolute URL)"
    print_header(f"Build: src/ → output/  [{mode_label}]")

    src = Path(CONFIG['src_dir'])
    out = Path(CONFIG['output_dir'])

    if not src.exists():
        print_error(f"Source folder not found: {src}")
        print_info("Create it with: mkdir -p src/frontend src/api src/recipes")
        print_info("Then place your master files inside.")
        return False

    # Choose output subfolder
    dest_root = out / 'website' / ('frontend-local' if local else 'frontend')

    # Copy src/ into output/ — flatten src/frontend/* → dest_root/ and src/api/* → dest_root/api/
    dest_root.mkdir(parents=True, exist_ok=True)
    for item in src.rglob('*'):
        if item.is_dir():
            continue
        rel = item.relative_to(src)
        parts = rel.parts
        # Flatten: src/frontend/foo.js → dest_root/foo.js (strip 'frontend/' prefix)
        if parts[0] == 'frontend':
            rel = Path(*parts[1:])
        dest = dest_root / rel
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(item, dest)

    site_base = CONFIG.get('site_base_path', '/momsrecipes')
    version   = datetime.now().strftime('%Y%m%d%H%M%S')

    if local:
        # Local: relative URL so python -m http.server can reach the JSON fallback
        api_url = 'api/index.php'
    else:
        # Production: absolute path on Namecheap
        api_url = f"{site_base}/api/index.php"

    # ── Patch frontend/index.html ────────────────────────────────────────────
    index_path = dest_root / 'frontend' / 'index.html'
    if index_path.exists():
        content = index_path.read_text(encoding='utf-8')

        # Inject API URL
        content = re.sub(
            r"const API_URL\s*=\s*['\"].*?['\"];",
            f"const API_URL = '{api_url}';",
            content
        )

        # ── Always fix: ensure import goes through PHP proxy, never direct Anthropic ──
        content = content.replace(
            "fetch('https://api.anthropic.com/v1/messages'",
            "fetch('" + site_base + "/api/index.php?action=import_recipe'"
        )
        content = content.replace(
            'fetch("https://api.anthropic.com/v1/messages"',
            'fetch("' + site_base + '/api/index.php?action=import_recipe"'
        )

        if local:
            # ── LOCAL: bypass auth overlay ───────────────────────────────────
            # Hide the auth overlay immediately; show main content immediately.
            # Replace the auth-overlay div's opening tag to add 'hidden' class.
            content = re.sub(
                r'(<div\s+id="auth-overlay"[^>]*class=")([^"]*auth-overlay[^"]*")',
                lambda m: m.group(1) + m.group(2).rstrip('"') + ' hidden"',
                content
            )
            # Make main-content visible immediately
            content = re.sub(
                r'(<div\s+id="main-content"[^>]*class=")([^"]*main-content[^"]*")',
                lambda m: m.group(1) + m.group(2).rstrip('"') + ' visible"',
                content
            )
            # Remove auth.js script tag entirely in local build
            content = re.sub(r'\s*<script\s+src="auth\.js[^"]*"[^>]*>\s*</script>', '', content)
            # No cache-busting in local (faster iteration)
            print_success(f"index.html — API_URL → '{api_url}' | auth overlay BYPASSED | auth.js removed")
        else:
            # ── PRODUCTION: auth on, cache-bust all assets ───────────────────
            content = re.sub(r'src="script\.js(\?v=[^"]*)?"\s*>',  f'src="script.js?v={version}">',  content)
            content = re.sub(r'src="auth\.js(\?v=[^"]*)?"\s*>',    f'src="auth.js?v={version}">',    content)
            content = re.sub(r'href="styles\.css(\?v=[^"]*)?"\s*>', f'href="styles.css?v={version}">', content)
            print_success(f"index.html — API_URL → '{api_url}' | auth ON | cache v={version}")

        index_path.write_text(content, encoding='utf-8')

    # ── Patch frontend/script.js ─────────────────────────────────────────────
    script_path = dest_root / 'frontend' / 'script.js'
    if script_path.exists():
        content = script_path.read_text(encoding='utf-8')
        content = re.sub(
            r"const API_BASE\s*=\s*['\"].*?['\"];",
            f"const API_BASE = '{api_url}';",
            content
        )
        script_path.write_text(content, encoding='utf-8')
        print_success(f"script.js  — API_BASE → '{api_url}'")

    dest_label = 'frontend-local' if local else 'frontend'
    print_success(f"Build complete → {dest_root}/")

    if local:
        print_info("Serve locally with:")
        print_info(f"  cd {dest_root} && python3 -m http.server 8000")
        print_info("  Then open: http://localhost:8000/")
        print_warning("PHP does not execute locally — API falls back to recipes.json")
    else:
        print_info("Deploy with: --deploy-frontend and/or --deploy-api")

    return True


def process_recipes():
    """Process Word documents using recipe_converter_namecheap.py."""
    print_header("Step 1: Processing Recipes and Images")

    if not os.path.exists(CONFIG['input_dir']):
        print_error(f"Input directory not found: {CONFIG['input_dir']}")
        return False

    os.makedirs(CONFIG['output_dir'], exist_ok=True)
    os.makedirs(CONFIG['images_dir'], exist_ok=True)

    try:
        print_info("Loading recipe converter...")
        if not os.path.exists('recipe_converter_namecheap.py'):
            print_error("recipe_converter_namecheap.py not found in current directory")
            return False

        sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
        from recipe_converter_namecheap import RecipeParser
        print_success("Recipe converter loaded")

        print_info("Scanning for Word documents (including subdirectories)...")
        recipe_files = []
        for ext in ['.docx', '.doc']:
            files = list(Path(CONFIG['input_dir']).rglob(f'*{ext}'))
            recipe_files.extend([f for f in files if not f.name.startswith('~$')])

        if not recipe_files:
            print_warning(f"No Word documents found in {CONFIG['input_dir']}")
            return False

        print_success(f"Found {len(recipe_files)} Word documents")

        folders = set()
        for f in recipe_files:
            rp = f.relative_to(CONFIG['input_dir'])
            if len(rp.parts) > 1:
                folders.add(rp.parent)
        if folders:
            print_info(f"Files in {len(folders)} subdirectories:")
            for folder in sorted(folders):
                cnt = sum(1 for f in recipe_files
                          if folder in f.relative_to(CONFIG['input_dir']).parents
                          or f.parent.relative_to(CONFIG['input_dir']) == folder)
                print(f"  📁 {folder}/ ({cnt} files)")

        parser = RecipeParser()
        all_recipes = []
        total_files = len(recipe_files)
        recipe_count_by_file = {}

        print()
        for idx, recipe_file in enumerate(recipe_files, 1):
            rel_path = recipe_file.relative_to(CONFIG['input_dir'])
            print_info(f"[{idx}/{total_files}] Processing: {rel_path}")
            category = str(rel_path.parent) if rel_path.parent != Path('.') else 'Uncategorized'

            try:
                recipes_from_file = parser.parse_document(str(recipe_file))
                if recipes_from_file:
                    valid = 0
                    for recipe in recipes_from_file:
                        if not recipe.get('ingredients') and not recipe.get('directions'):
                            print_warning(f"    ⚠ Skipping '{recipe.get('title', 'Untitled')}' - no content")
                            continue
                        api_recipe = {
                            'title': recipe.get('title', 'Untitled'),
                            'category': category,
                            'contributor': recipe.get('family_source', recipe.get('contributor', 'Unknown')),
                            'servings': recipe.get('servings', ''),
                            'prep_time': recipe.get('prep_time', ''),
                            'cook_time': recipe.get('cook_time', ''),
                            'ingredients': '\n'.join(recipe.get('ingredients', [])),
                            'directions': '\n'.join(recipe.get('directions', [])),
                            'tags': recipe.get('tags', ''),
                            'notes': '\n'.join(recipe.get('notes', [])),
                            'source_file': str(recipe_file),
                            'relative_path': str(rel_path),
                            # Metadata for colorized tags
                            'meal_type': recipe.get('meal_type', ''),
                            'cuisine': recipe.get('cuisine', ''),
                            'main_ingredient': recipe.get('main_ingredient', ''),
                            'method': recipe.get('method', ''),
                            'uuid': recipe.get('uuid', ''),
                            # Images extracted from Word docs
                            'images': recipe.get('images', []),
                        }
                        if recipe.get('image_path'):
                            try:
                                import base64
                                with open(recipe['image_path'], 'rb') as img:
                                    b64 = base64.b64encode(img.read()).decode('utf-8')
                                    api_recipe['image_data'] = f"data:image/jpeg;base64,{b64}"
                                    api_recipe['image_filename'] = os.path.basename(recipe['image_path'])
                            except:
                                pass
                        all_recipes.append(api_recipe)
                        valid += 1

                    recipe_count_by_file[recipe_file.name] = valid
                    if valid > 0:
                        print_success(f"  → {valid} recipes from {recipe_file.name}")
                    else:
                        print_warning(f"  → No valid recipes in {recipe_file.name}")
                    skipped = len(recipes_from_file) - valid
                    if skipped > 0:
                        print_warning(f"    ({skipped} skipped — missing content)")
                else:
                    print_warning(f"  → No recipes found in {recipe_file.name}")
            except Exception as e:
                print_error(f"  → Failed: {recipe_file.name}: {e}")
                if '--debug' in sys.argv:
                    import traceback; traceback.print_exc()

        if not all_recipes:
            print_error("\nNo recipes were extracted")
            return False

        # Generate HTML
        print()
        print_info("Generating HTML website files...")
        try:
            from recipe_converter_namecheap import HTMLGenerator
            website_dir = os.path.join(CONFIG['output_dir'], 'website')
            html_gen = HTMLGenerator(website_dir)
            conv = []
            for r in all_recipes:
                conv.append({
                    'title': r['title'],
                    'ingredients': r['ingredients'].split('\n') if r['ingredients'] else [],
                    'directions': r['directions'].split('\n') if r['directions'] else [],
                    'family_source': r['contributor'],
                    'notes': r['notes'].split('\n') if r['notes'] else [],
                    'tags': r.get('tags', ''), 'servings': r.get('servings', ''),
                    'prep_time': r.get('prep_time', ''), 'cook_time': r.get('cook_time', ''),
                    # Metadata for colorized tags on recipe pages
                    'meal_type': r.get('meal_type', ''),
                    'cuisine': r.get('cuisine', ''),
                    'main_ingredient': r.get('main_ingredient', ''),
                    'method': r.get('method', ''),
                    'uuid': r.get('uuid', ''),
                    # Images from Word documents
                    'images': r.get('images', []),
                })
            html_gen.generate_website(conv)
            print_success(f"Website generated at: {website_dir}")
        except Exception as e:
            print_warning(f"HTML generation failed: {e}")
            if '--debug' in sys.argv:
                import traceback; traceback.print_exc()

        # Save metadata
        meta_file = os.path.join(CONFIG['output_dir'], 'recipes_metadata.json')
        with open(meta_file, 'w', encoding='utf-8') as f:
            json.dump(all_recipes, f, indent=2, ensure_ascii=False)

        print()
        print_success(f"Total: {len(all_recipes)} recipes from {total_files} files")
        print_info("Uses 'directions' consistently ✓")

        if recipe_count_by_file:
            print()
            print_info("Top files:")
            for fn, c in sorted(recipe_count_by_file.items(), key=lambda x: -x[1])[:10]:
                print(f"  {fn}: {c} recipes")

        cats = {}
        for r in all_recipes:
            cat = r.get('category', 'Uncategorized')
            cats[cat] = cats.get(cat, 0) + 1
        if cats:
            print()
            print_info("By category:")
            for cat, c in sorted(cats.items(), key=lambda x: -x[1]):
                print(f"  {cat}: {c}")

        return True

    except ImportError as e:
        print_error(f"Import failed: {e}")
        print_info("Required: pip3 install python-docx Pillow lxml")
        return False
    except Exception as e:
        print_error(f"Processing failed: {e}")
        import traceback; traceback.print_exc()
        return False


# =============================================================================
# FTP HELPER
# =============================================================================

def ftp_connect():
    """Connect to FTP and navigate to remote directory (auto-detects Namecheap paths)."""
    ftp = ftplib.FTP()
    ftp.connect(CONFIG['ftp_host'], 21, timeout=120)
    ftp.login(CONFIG['ftp_user'], CONFIG['ftp_pass'])
    ftp.set_pasv(True)  # Passive mode — required for most shared hosting
    print_success(f"Connected to {CONFIG['ftp_host']}")

    ftp_root = ftp.pwd()
    print_info(f"FTP root (absolute):     {ftp_root}")
    print_info(f"Target from config:      {CONFIG['ftp_remote_dir']}")

    for path in [CONFIG['ftp_remote_dir'], '/public_html/momsrecipes', f'{ftp_root}/public_html/momsrecipes']:
        try:
            ftp.cwd(path)
            absolute = ftp.pwd()
            print_success(f"Found remote directory:  {path}")
            print_success(f"Absolute path on server: {absolute}")
            return ftp, path
        except:
            continue

    # Not found — create it
    print_info("Remote directory not found, creating...")
    for base in ['/public_html', f'{ftp_root}/public_html', CONFIG['ftp_remote_dir'].rsplit('/momsrecipes', 1)[0]]:
        try:
            ftp.cwd(base)
            try: ftp.mkd('momsrecipes')
            except: pass
            ftp.cwd('momsrecipes')
            rd = ftp.pwd()
            print_success(f"Created — absolute path: {rd}")
            return ftp, rd
        except:
            continue

    # Last resort: create from root one level at a time
    ftp.cwd(ftp_root)
    for part in CONFIG['ftp_remote_dir'].strip('/').split('/'):
        try:
            ftp.cwd(part)
        except:
            ftp.mkd(part)
            print_info(f"  Created: {part}/")
            ftp.cwd(part)
    rd = ftp.pwd()
    print_success(f"Created — absolute path: {rd}")
    return ftp, rd


# =============================================================================
# BACKUP DATABASE
# =============================================================================

def backup_database():
    """Download recipes.db from server → ./backups/recipes_TIMESTAMP.db"""
    print_header("Backing Up Database")
    os.makedirs(CONFIG['backup_dir'], exist_ok=True)

    try:
        ftp, remote_dir = ftp_connect()

        # Find the database
        db_found = False
        for subdir in ['api/data', 'api']:
            try:
                ftp.cwd(remote_dir)
                ftp.cwd(subdir)
                db_found = True
                break
            except:
                continue

        if not db_found:
            print_info("Could not find api/ directory — database may not exist yet")
            ftp.quit()
            return True

        try:
            file_list = ftp.nlst()
        except:
            file_list = []

        db_files = [f for f in file_list if f.endswith('.db') or f.endswith('.sqlite')]
        if not db_files:
            print_info("No database files on server (first deploy?)")
            ftp.quit()
            return True

        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backed_up = 0
        for db_file in db_files:
            name = f"{os.path.splitext(db_file)[0]}_{timestamp}{os.path.splitext(db_file)[1]}"
            path = os.path.join(CONFIG['backup_dir'], name)
            try:
                with open(path, 'wb') as f:
                    ftp.retrbinary(f'RETR {db_file}', f.write)
                sz = os.path.getsize(path)
                print_success(f"Backed up: {db_file} → {path} ({sz/1024:.1f} KB)")
                backed_up += 1
            except Exception as e:
                print_warning(f"Could not backup {db_file}: {e}")

        ftp.quit()

        if backed_up > 0:
            print_success(f"Backup complete ({backed_up} file(s))")
            # Keep only last 10 backups
            bdir = CONFIG['backup_dir']
            bfiles = sorted(
                [f for f in os.listdir(bdir) if f.endswith('.db') or f.endswith('.sqlite')],
                key=lambda f: os.path.getmtime(os.path.join(bdir, f)), reverse=True
            )
            for old in bfiles[10:]:
                os.remove(os.path.join(bdir, old))
                print_info(f"  Cleaned: {old}")

        return True

    except Exception as e:
        print_warning(f"Backup failed: {e} (continuing anyway)")
        return True  # Non-fatal


# =============================================================================
# DEPLOY FRONTEND (HTML/CSS/JS/images — skips api/, never uploads .db)
# =============================================================================



def serve_local():
    """Assemble a complete local dev environment and launch PHP built-in server.

    What it does:
      1. Finds most recent DB backup in ./backups/
      2. Copies output/website/momsrecipes/ into local-serve/
      3. Overlays src/api/ PHP files
      4. Writes a local config.php (all constants, no auth, no Anthropic key)
      5. Copies DB into api/data/recipes.db
      6. Patches API URLs and bypasses auth overlay
      7. Writes router.php (handles /recipes/{uuid} rewrites)
      8. Launches: php -S localhost:8000 router.php
    Open: http://localhost:8000
    """
    import shutil, re, subprocess, sys

    print_header("Local Dev Server")

    # ── 1. Find latest DB backup ──────────────────────────────────────────────
    backup_dir = CONFIG['backup_dir']
    db_source  = None

    if os.path.exists(backup_dir):
        db_files = sorted(
            [os.path.join(backup_dir, f) for f in os.listdir(backup_dir)
             if f.endswith('.db') or f.endswith('.sqlite')],
            key=os.path.getmtime, reverse=True
        )
        if db_files:
            db_source = db_files[0]

    if not db_source:
        print_error("No DB backup found in ./backups/ — run --backup-db first")
        return False

    print_success(f"Using DB: {db_source}")

    # ── 2. Build the serve directory ──────────────────────────────────────────
    serve_dir = Path(CONFIG['output_dir']) / 'website' / 'local-serve'
    serve_dir.mkdir(parents=True, exist_ok=True)

    # Copy generated recipe pages / images from momsrecipes output
    momsrecipes_out = Path(CONFIG['output_dir']) / 'website' / 'momsrecipes'
    if momsrecipes_out.exists():
        shutil.copytree(momsrecipes_out, serve_dir, dirs_exist_ok=True)
        print_success(f"Copied site output → {serve_dir}/")
    else:
        print_warning("No momsrecipes output found — run --process first for recipe pages")

    # ── 3a. Overlay src/frontend/ files (script.js, styles.css, auth.js, index.html)
    src_frontend = Path(CONFIG['src_dir']) / 'frontend'
    if src_frontend.exists():
        for item in src_frontend.rglob('*'):
            if item.is_file():
                rel  = item.relative_to(src_frontend)
                dest = serve_dir / rel
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(item, dest)
        print_success("Copied src/frontend/ files (script.js, styles.css, etc.)")
    else:
        print_warning("src/frontend/ not found — using output versions")

    # ── 3b. Overlay src/api/ PHP files ────────────────────────────────────────
    src_api = Path(CONFIG['src_dir']) / 'api'
    dest_api = serve_dir / 'api'
    dest_api.mkdir(parents=True, exist_ok=True)

    if src_api.exists():
        for item in src_api.rglob('*'):
            if item.is_file():
                rel  = item.relative_to(src_api)
                dest = dest_api / rel
                dest.parent.mkdir(parents=True, exist_ok=True)
                # Skip config.php — we'll write a local version
                if item.name == 'config.php':
                    continue
                shutil.copy2(item, dest)
        print_success("Copied src/api/ PHP files")
    else:
        print_error("src/api/ not found")
        return False

    # ── 4. Write a local config.php — pull Anthropic key from real config ──────
    local_config = dest_api / 'config.php'
    db_local_path = dest_api / 'data' / 'recipes.db'

    # Try to extract the real Anthropic API key from src/api/config.php
    anthropic_key = ''
    real_config = Path(CONFIG['src_dir']) / 'api' / 'config.php'
    if real_config.exists():
        import re as _re
        match = _re.search(r"define\('ANTHROPIC_API_KEY'\s*,\s*'([^']+)'\)", real_config.read_text())
        if match:
            anthropic_key = match.group(1)
            print_success("  Extracted Anthropic API key from src/api/config.php")

    local_config.write_text(f"""<?php
// Local dev config — generated by --serve
define('DB_PATH', __DIR__ . '/data/recipes.db');
define('DB_BACKUP_DIR', __DIR__ . '/data/backups');
define('API_VERSION', '1.0-local');
define('MAX_RECIPE_TITLE_LENGTH', 200);
define('MAX_IMAGE_SIZE', 5242880);
date_default_timezone_set('America/Los_Angeles');
define('AUTH_ENABLED', false);
define('API_KEY', 'local-dev');
define('ANTHROPIC_API_KEY', '{anthropic_key}');
define('LOG_FILE', __DIR__ . '/logs/api.log');
define('LOG_ENABLED', false);
define('AUTO_BACKUP_ENABLED', false);
define('BACKUP_RETENTION_DAYS', 30);
define('IS_PRODUCTION', false);
define('RATE_LIMIT_ENABLED', false);
ini_set('memory_limit', '512M');
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 3600);
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (!is_dir(__DIR__ . '/data'))    mkdir(__DIR__ . '/data',    0755, true);
if (!is_dir(__DIR__ . '/logs'))    mkdir(__DIR__ . '/logs',    0755, true);
if (!is_dir(DB_BACKUP_DIR))        mkdir(DB_BACKUP_DIR,        0755, true);
""", encoding='utf-8')
    print_success("Wrote local config.php (no auth, no Anthropic key)")

    # ── 5. Copy DB ─────────────────────────────────────────────────────────────
    db_dest_dir = dest_api / 'data'
    db_dest_dir.mkdir(parents=True, exist_ok=True)
    shutil.copy2(db_source, db_dest_dir / 'recipes.db')
    print_success(f"Copied DB → api/data/recipes.db")

    # ── 6. Patch index.html: local API URL + auth bypass ─────────────────────
    index_html = serve_dir / 'index.html'
    if index_html.exists():
        txt = index_html.read_text(encoding='utf-8')
        # Fix API URLs to relative (no /momsrecipes/ prefix)
        txt = re.sub(r"const API_URL\s*=\s*['\"].*?['\"];",       "const API_URL = 'api/index.php';",  txt)
        txt = re.sub(r"const API_BASE\s*=\s*['\"].*?['\"];",      "const API_BASE = 'api/index.php';", txt)
        txt = re.sub(r"var IMPORT_API_BASE\s*=\s*['\"].*?['\"];", "var IMPORT_API_BASE = 'api/index.php';", txt)
        txt = re.sub(r"/momsrecipes/api/index\.php", "api/index.php", txt)
        # Ensure import never calls Anthropic directly — always via PHP proxy
        txt = txt.replace("fetch('https://api.anthropic.com/v1/messages'", "fetch('api/index.php?action=import_recipe'")
        txt = txt.replace('fetch("https://api.anthropic.com/v1/messages"', 'fetch("api/index.php?action=import_recipe"')
        # Bypass auth overlay — hide it immediately, show main content
        txt = re.sub(
            r'(<div\s+id="auth-overlay"[^>]*class=")(auth-overlay[^"]*")',
            lambda m: m.group(1) + m.group(2).rstrip('"') + ' hidden"',
            txt
        )
        txt = re.sub(
            r'(<div\s+id="main-content"[^>]*class=")(main-content[^"]*")',
            lambda m: m.group(1) + m.group(2).rstrip('"') + ' visible"',
            txt
        )
        # Remove auth.js script tag
        txt = re.sub(r'\s*<script\s+src="auth\.js[^"]*"[^>]*>\s*</script>', '', txt)
        # Inject a fake logged-in user so API calls work
        fake_user = """
    <script>
    // Local dev: inject fake user session so API calls include username
    if (!localStorage.getItem('momsrecipes_current_user')) {
        localStorage.setItem('momsrecipes_current_user', JSON.stringify({
            username: 'localdev', fullname: 'Local Dev', token: 'local-token'
        }));
    }
    </script>"""
        txt = txt.replace('</body>', fake_user + '\n</body>', 1)
        index_html.write_text(txt, encoding='utf-8')
        print_success("Patched index.html → local API URLs + auth bypassed")

    # Patch script.js: API URL + recipe URLs
    script_js = serve_dir / 'script.js'
    if script_js.exists():
        txt = script_js.read_text(encoding='utf-8')
        txt = re.sub(r"const API_BASE\s*=\s*['\"].*?['\"];", "const API_BASE = 'api/index.php';", txt)
        # Fix getRecipeUrl to use relative paths (remove /momsrecipes prefix)
        txt = txt.replace('/momsrecipes/recipes/${recipe.uuid}.html', 'recipes/${recipe.uuid}.html')
        txt = txt.replace('/momsrecipes/recipes/${slug}.html',        'recipes/${slug}.html')
        txt = txt.replace('/momsrecipes/recipes/',                    'recipes/')
        script_js.write_text(txt, encoding='utf-8')
        print_success("Patched script.js → local API URL + relative recipe URLs")

    # ── 7. Write .htaccess for local PHP server ────────────────────────────────
    # PHP built-in server doesn't use mod_rewrite — use a router instead
    router = serve_dir / 'router.php'
    router.write_text("""<?php
// Local dev router for php -S localhost:8000
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Strip optional /momsrecipes prefix so local and production URLs both work
$uri = preg_replace('#^/momsrecipes#', '', $uri);
if ($uri === '') $uri = '/';

// Route /recipes/{uuid} or /recipes/{uuid}.html → api/recipe.php?id={uuid}
if (preg_match('#^/recipes/([a-f0-9-]+)(\\.html)?$#i', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/api/recipe.php';
    exit;
}

// Serve existing static files as-is
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// Default: serve index.html
require __DIR__ . '/index.html';
""", encoding='utf-8')
    print_success("Wrote router.php for PHP built-in server")

    # ── 8. Launch PHP server ───────────────────────────────────────────────────
    print()
    print_success(f"Local site ready at: {serve_dir}/")
    print_info("Starting PHP server on http://localhost:8000 ...")
    print_info("Open: http://localhost:8000  (auth bypassed, logged in as 'localdev')")
    print_info("Press Ctrl+C to stop")
    print()

    try:
        subprocess.run(
            ['php', '-S', 'localhost:8000', 'router.php'],
            cwd=str(serve_dir),
            check=False
        )
    except FileNotFoundError:
        print_error("PHP not found — install with: brew install php")
        print_info(f"Then run manually: cd {serve_dir} && php -S localhost:8000 router.php")
        return False
    except KeyboardInterrupt:
        print()
        print_success("Server stopped")

    return True


def stamp_database():
    """Fast server-side backup: writes a timestamp marker file in api/data/.
    Runs in seconds instead of minutes. Use --backup-db for a full download."""
    from datetime import datetime
    import io
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    print_info(f"Stamping database on server ({timestamp})...")
    try:
        ftp, remote_dir = ftp_connect()
        db_found = False
        for subdir in [['api', 'data'], ['api']]:
            try:
                ftp.cwd(remote_dir)
                for part in subdir:
                    ftp.cwd(part)
                db_found = True
                break
            except:
                continue

        if not db_found:
            print_warning("Could not find db directory — skipping stamp")
            ftp.quit()
            return True

        marker = f"backup_stamp_{timestamp}.txt"
        data = f"DB stamp: {timestamp}\nCreated before deploy\n".encode()
        ftp.storbinary(f'STOR {marker}', io.BytesIO(data))
        print_success(f"  Stamp written: {marker}")

        # Keep only last 10 stamps
        try:
            files = ftp.nlst()
            stamps = sorted([f for f in files if f.startswith('backup_stamp_') and f.endswith('.txt')])
            for old in stamps[:-10]:
                try: ftp.delete(old)
                except: pass
        except: pass

        ftp.quit()
        print_success("Server stamped — run --backup-db anytime for a full download")
        return True
    except Exception as e:
        print_warning(f"Stamp failed: {e} (non-fatal, continuing)")
        return True


def deploy_frontend():
    """Deploy code-only frontend files: index.html, CSS, JS, auth.js, favicon.
    Does NOT deploy recipes/ or images/ — those are content, deployed by --update-db.

    Always deploys from output/website/frontend/ (the --build output).
    Never falls back silently to the legacy momsrecipes/ path — that path is
    generated by the converter and does NOT contain auth.js.
    """
    print_header("Deploying Frontend Code (Production)")
    print_info("Deploys: index.html, styles.css, script.js, auth.js, favicon")
    print_info("Skips:   recipes/, images/  (use --update-db for content)")

    # Code-only files to deploy from the momsrecipes root
    CODE_EXTENSIONS = {'.html', '.css', '.js', '.ico', '.json'}
    # Directories that are content — never touched here
    CONTENT_DIRS = {'recipes', 'images', 'api'}

    src_built = os.path.join(CONFIG['output_dir'], 'website', 'frontend')
    legacy    = os.path.join(CONFIG['output_dir'], 'website', 'momsrecipes')

    if os.path.exists(src_built):
        website_dir = src_built
        print_info(f"Source: {src_built}")
    elif os.path.exists(legacy):
        print_error("output/website/frontend/ not found — cannot deploy.")
        print_warning("The legacy output/website/momsrecipes/ path exists but does NOT")
        print_warning("contain auth.js and will produce a broken site if deployed.")
        print_info("Fix: run  python3 recipe_pipeline.py --build  first, then retry.")
        return False
    else:
        print_error("No built frontend found. Run --build first.")
        print_info("  python3 recipe_pipeline.py --build")
        return False

    # Verify auth.js is present before deploying
    auth_js_candidates = [
        os.path.join(website_dir, 'frontend', 'auth.js'),
        os.path.join(website_dir, 'auth.js'),
    ]
    if not any(os.path.exists(p) for p in auth_js_candidates):
        print_error("auth.js not found in built output — refusing to deploy.")
        print_warning("Without auth.js the site shows only a polka-dot background.")
        print_info("Ensure src/frontend/auth.js exists, then run --build again.")
        return False
    print_success("auth.js verified present in build")

    try:
        ftp, remote_dir = ftp_connect()
        uploaded = 0; skipped = 0; failed = 0

        # Upload main site index.html one level up (public_html/index.html)
        main_index = os.path.join(os.path.dirname(website_dir), 'index.html')
        if os.path.exists(main_index):
            try:
                ftp.cwd(remote_dir)
                ftp.cwd('..')
                with open(main_index, 'rb') as f:
                    ftp.storbinary('STOR index.html', f)
                uploaded += 1
                print_success(f"  index.html (main landing page) → {ftp.pwd()}/")
            except Exception as e:
                print_warning(f"  Could not upload main index.html: {e}")

        # Walk the momsrecipes directory, skip content dirs
        print()
        print_info("Uploading momsrecipes/ code files...")
        for root, dirs, files in os.walk(website_dir):
            # Prune content directories so os.walk never descends into them
            dirs[:] = [d for d in dirs if d not in CONTENT_DIRS]

            rel_path = os.path.relpath(root, website_dir)
            ftp.cwd(remote_dir)
            if rel_path != '.':
                for part in rel_path.replace('\\', '/').split('/'):
                    try: ftp.cwd(part)
                    except:
                        try: ftp.mkd(part); ftp.cwd(part)
                        except: print_warning(f"  Could not create: {part}/")

            for filename in files:
                if is_excluded(filename):
                    skipped += 1; continue
                ext = os.path.splitext(filename)[1].lower()
                if ext not in CODE_EXTENSIONS:
                    skipped += 1; continue
                local_file = os.path.join(root, filename)
                try:
                    with open(local_file, 'rb') as f:
                        ftp.storbinary(f'STOR {filename}', f)
                    uploaded += 1
                    sz = os.path.getsize(local_file) / 1024
                    print_success(f"  {os.path.relpath(local_file, website_dir)} ({sz:.1f} KB)")
                except Exception as e:
                    print_error(f"  Failed: {filename}: {e}"); failed += 1

            ftp.cwd(remote_dir)

        ftp.quit()
        print()
        print_success(f"Frontend code: {uploaded} uploaded, {skipped} skipped, {failed} failed")
        print_info("Run --update-db to deploy recipe pages and images")
        return failed == 0

    except Exception as e:
        print_error(f"Frontend deploy failed: {e}")
        import traceback; traceback.print_exc()
        return False


# =============================================================================
# DEPLOY API (api.php, .htaccess — never .db files)
# =============================================================================

def fix_server():
    """Remove problematic .htaccess from api/ directory on server."""
    print_header("Fixing Server (removing api/.htaccess)")
    try:
        ftp, remote_dir = ftp_connect()
        ftp.cwd(remote_dir)

        # Navigate into api/
        try:
            ftp.cwd('api')
            print_info(f"Inside api/ — absolute path: {ftp.pwd()}")
        except Exception:
            print_error("Could not navigate to api/ directory on server")
            ftp.quit()
            return False

        # Try to delete .htaccess
        try:
            ftp.delete('.htaccess')
            print_success("Deleted api/.htaccess from server")
        except Exception as e:
            print_warning(f"Could not delete .htaccess (may not exist): {e}")

        ftp.quit()
        print_info("Now re-run: python3 recipe_pipeline.py --update-db")
        return True
    except Exception as e:
        print_error(f"fix_server failed: {e}")
        return False



def deploy_template():
    """Deploy the dynamic recipe PHP template and .htaccess rewrite rules.
    Uploads recipe.php to api/ and .htaccess to the momsrecipes root.
    After this, all /recipes/{uuid}.html and /recipes/{uuid} URLs are
    served dynamically from the database — no static HTML files needed."""
    print_header("Deploying Dynamic Recipe Template")

    recipe_php = None
    for candidate in ['./src/api/recipe.php', './api/recipe.php']:
        if os.path.exists(candidate):
            recipe_php = candidate
            break
    if not recipe_php:
        print_error("recipe.php not found — expected at src/api/recipe.php")
        return False

    htaccess = None
    for candidate in ['./src/.htaccess', './.htaccess']:
        if os.path.exists(candidate):
            htaccess = candidate
            break
    if not htaccess:
        print_error(".htaccess not found — expected at src/.htaccess")
        return False

    print_info(f"recipe.php : {recipe_php}")
    print_info(f".htaccess  : {htaccess}")

    try:
        ftp, remote_dir = ftp_connect()
        ftp.cwd(remote_dir)

        # Upload .htaccess to momsrecipes root
        with open(htaccess, 'rb') as f:
            ftp.storbinary('STOR .htaccess', f)
        print_success("  Uploaded: .htaccess → momsrecipes/")

        # Navigate into api/ and upload recipe.php
        try:
            ftp.cwd('api')
        except:
            ftp.mkd('api')
            ftp.cwd('api')
        with open(recipe_php, 'rb') as f:
            ftp.storbinary('STOR recipe.php', f)
        print_success("  Uploaded: recipe.php → momsrecipes/api/")

        ftp.quit()
        print()
        print_success("Dynamic template deployed!")
        print_info("All recipe URLs now served by recipe.php from the database.")
        print_info("Old .html URLs still work via .htaccess rewrite — no broken links.")
        return True

    except Exception as e:
        print_error(f"Template deploy failed: {e}")
        import traceback; traceback.print_exc()
        return False


def deploy_api():
    """Deploy only API backend files. Never uploads database files."""
    print_header("Deploying API Backend")

    api_source = None
    for d in ['./src/api',
              os.path.join(CONFIG['output_dir'], 'website', 'api'),
              os.path.join(CONFIG['output_dir'], 'api'), './api']:
        if os.path.exists(d):
            api_source = d
            break

    if not api_source:
        print_error("No API files found (checked output/website/api, output/api, ./api)")
        return False

    print_info(f"API source: {api_source}")

    uploadable = []
    for f in os.listdir(api_source):
        if os.path.isfile(os.path.join(api_source, f)):
            if is_excluded(f):
                print_warning(f"  SKIP (excluded): {f}")
            else:
                uploadable.append(f)
                print_info(f"  Will upload: {f}")

    if not uploadable:
        print_error("No uploadable API files (all excluded or empty)")
        return False

    try:
        ftp, remote_dir = ftp_connect()
        ftp.cwd(remote_dir)

        # Create api/ directory
        try:
            ftp.cwd('api')
            print_info(f"Inside api/ — absolute path: {ftp.pwd()}")
        except:
            try:
                ftp.mkd('api'); print_info("  Created: api/")
                ftp.cwd('api')
                print_info(f"Inside api/ — absolute path: {ftp.pwd()}")
            except Exception as e:
                print_error(f"Cannot create api/: {e}")
                ftp.quit(); return False

        # Create data/ for auto-created DB
        try: ftp.cwd('data'); ftp.cwd('..')
        except:
            try: ftp.mkd('data'); print_info("  Created: api/data/")
            except: pass

        uploaded = 0; failed = 0
        for filename in uploadable:
            if is_excluded(filename):
                continue
            try:
                with open(os.path.join(api_source, filename), 'rb') as f:
                    ftp.storbinary(f'STOR {filename}', f)
                uploaded += 1
                print_success(f"  Uploaded: {filename}")
            except Exception as e:
                print_error(f"  Failed: {filename} — {e}")
                failed += 1

        ftp.quit()
        print()
        print_success(f"API: {uploaded} uploaded, {failed} failed")
        print_info("recipes.db is NEVER uploaded — auto-created by PHP on server")
        print_info(f"Test: {CONFIG['api_url']}/health")
        return failed == 0

    except Exception as e:
        print_error(f"API deploy failed: {e}")
        import traceback; traceback.print_exc()
        return False


# =============================================================================
# FULL DEPLOY (backup → frontend → API)
# =============================================================================

def deploy_to_server():
    """Full deploy: backup DB → frontend → API."""
    print_header("Step 2: Full Deployment")

    print_info("2a: Backing up database...")
    backup_database()

    print()
    print_info("2b: Frontend...")
    if not deploy_frontend():
        print_error("Frontend failed"); return False

    print()
    print_info("2c: API backend...")
    if not deploy_api():
        print_warning("API failed (frontend was deployed)")
        return False

    print()
    print_success("Full deployment complete!")
    return True


# =============================================================================
# STEP 3: UPDATE DATABASE VIA API
# =============================================================================

# =============================================================================
# UPDATE APPROVED USERS (upload approved_users.json only)
# =============================================================================

def update_approved_users():
    """Upload approved_users.json to the server's momsrecipes/ directory.
    
    Edit approved_users.json locally:
        {"users": ["paul", "sarah", "margaret", "john", "admin"]}
    
    Then run: python3 recipe_pipeline.py --update-users
    """
    print_header("Updating Approved Users")

    # Find or create approved_users.json
    local_file = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'approved_users.json')

    if not os.path.exists(local_file):
        # Create default file
        default_users = {"users": ["paul", "sarah", "margaret", "john", "admin"]}
        with open(local_file, 'w') as f:
            json.dump(default_users, f, indent=2)
        print_info(f"Created default approved_users.json")
        print_info(f"Edit this file to add/remove users, then re-run --update-users")

    # Load and display current users
    try:
        with open(local_file, 'r') as f:
            data = json.load(f)
        users = data.get('users', [])
        print_info(f"Approved users ({len(users)}):")
        for u in users:
            print(f"  • {u}")
    except Exception as e:
        print_error(f"Could not read approved_users.json: {e}")
        return False

    # Upload via FTP
    try:
        ftp, remote_dir = ftp_connect()

        # Navigate to momsrecipes/ directory
        ftp.cwd(remote_dir)

        # Upload the file
        with open(local_file, 'rb') as f:
            ftp.storbinary('STOR approved_users.json', f)

        print_success(f"Uploaded approved_users.json → {remote_dir}/approved_users.json")

        ftp.quit()

        # Sync the JSON into the approved_users DB table
        print_info("Syncing approved users into database...")
        try:
            sync_resp = requests.post(
                f"{CONFIG['api_url']}?action=sync_approved_users",
                timeout=60, verify=False
            )
            if sync_resp.ok:
                result = sync_resp.json()
                synced_users = result.get('users', [])
                print_success(f"Synced {len(synced_users)} users into database: {', '.join(synced_users)}")
            else:
                print_warning(f"Sync API call failed ({sync_resp.status_code}) — users will be read from JSON file directly")
        except Exception as sync_e:
            print_warning(f"Could not call sync API: {sync_e} — users will be read from JSON file directly")

        print()
        print_success("Approved users updated on server!")
        print_info("Changes take effect immediately — no redeploy needed")
        print_info(f"To edit: open approved_users.json, then run --update-users")

        return True

    except Exception as e:
        print_error(f"Failed to upload approved users: {e}")
        import traceback; traceback.print_exc()
        return False


def update_database():
    """Deploy all content to the server:
      1. Push recipe records to the SQLite database via API
      2. Upload recipes/ HTML pages via FTP
      3. Upload images/ via FTP
    Run --process first to build these locally.
    """
    print_header("Step 3: Deploying Content (DB + recipes/ + images/)")

    meta_file = os.path.join(CONFIG['output_dir'], 'recipes_metadata.json')
    if not os.path.exists(meta_file):
        print_error(f"Metadata not found: {meta_file}")
        print_info("Run --process first")
        return False

    # -------------------------------------------------------------------------
    # Part A: Push recipe records to the database via API
    # -------------------------------------------------------------------------
    print_info("Part A: Syncing recipe records to database...")
    db_failed = 0
    try:
        with open(meta_file, 'r', encoding='utf-8') as f:
            recipes = json.load(f)

        print_info(f"Found {len(recipes)} recipes to sync")
        print_info(f"API endpoint: {CONFIG['api_url']}")

        # Connectivity check
        try:
            test_resp = requests.get(f"{CONFIG['api_url']}?action=get_recipes_search", timeout=60, verify=False)
            if test_resp.status_code == 404:
                print_warning("API 404 on check — treating as empty DB (first run)")
            elif not test_resp.ok:
                print_error(f"API connectivity check FAILED: {test_resp.status_code}")
                print_error(f"Response: {test_resp.text[:300]}")
                if test_resp.status_code == 403:
                    print_error("403 Forbidden — bad .htaccess is blocking requests.")
                    print_info("FIX: Run: python3 recipe_pipeline.py --fix-server")
                else:
                    print_info("Check: 1) index.php in api/? 2) api/data/ writable?")
                return False
            else:
                try:
                    live_count = len(test_resp.json()) if isinstance(test_resp.json(), list) else 0
                    print_info(f"API connected OK ({live_count} recipes currently in DB)")
                except Exception:
                    print_warning(f"API non-JSON response: {test_resp.text[:200]}")
                    return False
        except requests.Timeout:
            print_error("API connectivity check timed out")
            return False
        except Exception as conn_e:
            print_error(f"API connectivity check error: {conn_e}")
            return False

        created = 0; updated = 0; failed_recipes = []

        def normalize(field):
            if not field: return ''
            if isinstance(field, str): return field.strip()
            if isinstance(field, list): return '\n'.join(str(i).strip() for i in field if i)
            return str(field).strip()

        def ensure_field(data, field, fallback_field, fallback_msg):
            val = normalize(data.get(field, ''))
            if not val or '1. No' in val:
                other = normalize(data.get(fallback_field, ''))
                if other and '1. No' not in other:
                    data[field] = f'1. No {field} provided - see {fallback_field}'
                else:
                    data[field] = f'1. No {field} listed'
                print_warning(f"  → '{data.get('title', '?')}' missing {field}, using placeholder")

        # Fetch full live list ONCE up front — not once per recipe (was 320 API calls)
        print_info("Fetching current DB contents...")
        try:
            all_live_resp = requests.get(f"{CONFIG['api_url']}?action=get_recipes_search", timeout=60, verify=False)
            all_live = all_live_resp.json() if all_live_resp.ok and isinstance(all_live_resp.json(), list) else []
        except Exception:
            all_live = []
        live_by_title = {r.get('title', '').strip().lower(): r for r in all_live}
        print_info(f"Live DB has {len(live_by_title)} recipes")
        print()

        for idx, recipe in enumerate(recipes, 1):
            try:
                title_lower = recipe['title'].strip().lower()
                existing = live_by_title.get(title_lower)

                # Resolve image_data from images[] list if not already set
                if not recipe.get('image_data') and recipe.get('images'):
                    images_local_dir = os.path.join(
                        CONFIG['output_dir'], 'website', 'momsrecipes', 'images'
                    )
                    first_img = recipe['images'][0]
                    img_path = os.path.join(images_local_dir, first_img)
                    if os.path.exists(img_path):
                        try:
                            import base64
                            with open(img_path, 'rb') as img_f:
                                b64 = base64.b64encode(img_f.read()).decode('utf-8')
                            ext = os.path.splitext(first_img)[1].lower().lstrip('.')
                            mime = 'jpeg' if ext in ('jpg', 'jpeg') else ext
                            recipe['image_data'] = f"data:image/{mime};base64,{b64}"
                            recipe['image_filename'] = first_img
                        except Exception as img_e:
                            print_warning(f"  Could not encode image for {recipe['title']}: {img_e}")

                if existing:
                    # UPDATE — build a clean payload without image_data (images deployed via FTP)
                    rid = existing['id']
                    update_payload = {
                        'title':          recipe.get('title', ''),
                        'category':       recipe.get('category', ''),
                        'contributor':    recipe.get('contributor', ''),
                        'servings':       recipe.get('servings', ''),
                        'prep_time':      recipe.get('prep_time', ''),
                        'cook_time':      recipe.get('cook_time', ''),
                        'ingredients':    normalize(recipe.get('ingredients', '')),
                        'directions':     normalize(recipe.get('directions', '')),
                        'tags':           recipe.get('tags', ''),
                        'notes':          recipe.get('notes', ''),
                        'meal_type':      recipe.get('meal_type', ''),
                        'cuisine':        recipe.get('cuisine', ''),
                        'main_ingredient':recipe.get('main_ingredient', ''),
                        'method':         recipe.get('method', ''),
                        'occasion':       recipe.get('occasion', ''),
                        'uuid':           recipe.get('uuid', ''),
                    }
                    ensure_field(update_payload, 'ingredients', 'directions', 'directions')
                    ensure_field(update_payload, 'directions', 'ingredients', 'ingredients')

                    # Send primary image filename (no base64 — file is deployed via FTP)
                    all_imgs = recipe.get('images', [])
                    if recipe.get('image_filename'):
                        update_payload['image_filename'] = recipe['image_filename']
                        extra_imgs = [f for f in all_imgs if f != recipe['image_filename']]
                    elif all_imgs:
                        update_payload['image_filename'] = all_imgs[0]
                        extra_imgs = all_imgs[1:]
                    else:
                        extra_imgs = []

                    # Send extra images as JSON array (new images column)
                    if extra_imgs:
                        import json as _json
                        update_payload['images'] = _json.dumps(extra_imgs)
                    resp = requests.post(
                        f"{CONFIG['api_url']}?action=update_recipe&id={rid}",
                        json=update_payload,
                        headers={'Content-Type': 'application/json'}, timeout=60, verify=False
                    )
                    if resp.ok:
                        updated += 1
                        if idx % 25 == 0: print_info(f"  Progress: {idx}/{len(recipes)}...")
                    else:
                        db_failed += 1
                        error_detail = resp.text[:500]
                        print_error(f"  Update failed: {recipe['title']} ({resp.status_code})")
                        if resp.status_code == 500:
                            payload_size = len(json.dumps(update_payload).encode('utf-8'))
                            print_error(f"  Payload size (sent): {payload_size/1024:.1f} KB")
                            print_error(f"  Server response: {error_detail[:300]}")
                        failed_recipes.append({'title': recipe['title'], 'op': 'update',
                                               'status': resp.status_code, 'error': error_detail})
                else:
                    # CREATE
                    rd = {
                        'title':          recipe.get('title', 'Untitled'),
                        'category':       recipe.get('category', ''),
                        'contributor':    recipe.get('contributor', ''),
                        'servings':       recipe.get('servings', ''),
                        'prep_time':      recipe.get('prep_time', ''),
                        'cook_time':      recipe.get('cook_time', ''),
                        'ingredients':    normalize(recipe.get('ingredients', '')),
                        'directions':     normalize(recipe.get('directions', '')),
                        'tags':           recipe.get('tags', ''),
                        'notes':          recipe.get('notes', ''),
                        'meal_type':      recipe.get('meal_type', ''),
                        'cuisine':        recipe.get('cuisine', ''),
                        'main_ingredient':recipe.get('main_ingredient', ''),
                        'method':         recipe.get('method', ''),
                        'occasion':       recipe.get('occasion', ''),
                        'uuid':           recipe.get('uuid', ''),
                    }
                    ensure_field(rd, 'ingredients', 'directions', 'directions')
                    ensure_field(rd, 'directions', 'ingredients', 'ingredients')

                    # Primary image — prefer base64 if available and not too large,
                    # otherwise just send the filename (file deployed via FTP)
                    all_imgs = recipe.get('images', [])
                    if recipe.get('image_data') and len(recipe.get('image_data', '')) < 5_000_000:
                        rd['image_data'] = recipe['image_data']
                        rd['image_filename'] = recipe.get('image_filename', 'image.jpg')
                        extra_imgs = [f for f in all_imgs if f != rd['image_filename']]
                    elif recipe.get('image_filename'):
                        rd['image_filename'] = recipe['image_filename']
                        extra_imgs = [f for f in all_imgs if f != recipe['image_filename']]
                    elif all_imgs:
                        rd['image_filename'] = all_imgs[0]
                        extra_imgs = all_imgs[1:]
                    else:
                        extra_imgs = []

                    # Send extra images as JSON array (new images column)
                    if extra_imgs:
                        import json as _json
                        rd['images'] = _json.dumps(extra_imgs)

                    resp = requests.post(
                        f"{CONFIG['api_url']}?action=create_recipe",
                        json=rd,
                        headers={'Content-Type': 'application/json'}, timeout=60, verify=False
                    )
                    if resp.ok:
                        created += 1
                        try:
                            new_id = resp.json().get('recipe', {}).get('id')
                            live_by_title[title_lower] = {'id': new_id, 'title': recipe['title']}
                        except Exception:
                            pass
                        if idx % 25 == 0: print_info(f"  Progress: {idx}/{len(recipes)}...")
                    else:
                        db_failed += 1
                        try: detail = resp.json().get('error', resp.text[:200])
                        except: detail = resp.text[:200]
                        print_error(f"  Create failed: {recipe['title']} ({resp.status_code}): {detail}")
                        failed_recipes.append({'title': recipe['title'], 'op': 'create',
                                               'status': resp.status_code, 'error': detail})

            except requests.Timeout:
                db_failed += 1
                print_error(f"  Timeout: {recipe.get('title', '?')}")
                failed_recipes.append({'title': recipe.get('title', '?'), 'op': 'timeout', 'error': 'timeout'})
            except Exception as e:
                db_failed += 1
                print_error(f"  Error: {recipe.get('title', '?')}: {e}")
                failed_recipes.append({'title': recipe.get('title', '?'), 'op': 'exception', 'error': str(e)})

        print()
        print_success(f"DB sync — Created: {created}  Updated: {updated}  Failed: {db_failed}")
        if db_failed > 0:
            fail_file = os.path.join(CONFIG['output_dir'], 'failed_recipes.json')
            with open(fail_file, 'w', encoding='utf-8') as f:
                json.dump(failed_recipes, f, indent=2, ensure_ascii=False)
            print_info(f"Failed details: {fail_file}")

    except Exception as e:
        print_error(f"Database sync failed: {e}")
        import traceback; traceback.print_exc()
        return False

    # -------------------------------------------------------------------------
    # Part B: Upload recipes/ and images/ via FTP
    # -------------------------------------------------------------------------
    print()
    print_info("Part B: Uploading content folders via FTP...")

    local_momsrecipes = os.path.join(CONFIG['output_dir'], 'website', 'momsrecipes')
    local_recipes_dir = os.path.join(local_momsrecipes, 'recipes')
    local_images_dir  = os.path.join(local_momsrecipes, 'images')

    # Static recipes/ HTML files are NOT uploaded — recipe.php serves all
    # recipe pages dynamically from the DB via .htaccess rewrite rules.
    # Only images/ needs to be FTP-synced.
    has_recipes = False  # skipped intentionally
    has_images  = os.path.exists(local_images_dir)

    if not has_images:
        print_warning("No local images/ folder found — skipping FTP upload")
        print_info(f"Expected at: {local_momsrecipes}")
        return db_failed == 0

    def ftp_upload_dir(ftp, local_dir, remote_base, label):
        """Walk local_dir and upload all files into remote_base, mirroring subdirs."""
        uploaded = 0; failed = 0; skipped = 0
        for root, dirs, files in os.walk(local_dir):
            rel = os.path.relpath(root, local_dir)
            ftp.cwd(remote_base)
            if rel != '.':
                for part in rel.replace('\\', '/').split('/'):
                    try: ftp.cwd(part)
                    except:
                        try: ftp.mkd(part); ftp.cwd(part)
                        except: print_warning(f"  Could not create {part}/")
            for filename in files:
                if is_excluded(filename):
                    skipped += 1; continue
                local_file = os.path.join(root, filename)
                if (uploaded + failed) % 20 == 0:
                    try: ftp.voidcmd('NOOP')
                    except: pass
                for attempt in range(3):
                    try:
                        with open(local_file, 'rb') as f:
                            ftp.storbinary(f'STOR {filename}', f)
                        uploaded += 1
                        sz = os.path.getsize(local_file) / 1024
                        if sz > 50:
                            print_success(f"  {label}/{os.path.relpath(local_file, local_dir)} ({sz:.1f} KB)")
                        elif uploaded % 50 == 0:
                            print_info(f"  {label}: {uploaded} files...")
                        break
                    except Exception as e:
                        if attempt < 2:
                            try: ftp.voidcmd('NOOP')
                            except: pass
                        else:
                            print_error(f"  Failed: {filename}: {e}")
                            failed += 1
            ftp.cwd(remote_base)
        return uploaded, failed, skipped

    try:
        ftp, remote_dir = ftp_connect()
        total_uploaded = 0; total_failed = 0

        if has_images:
            count = sum(len(fs) for _, _, fs in os.walk(local_images_dir))
            print_info(f"Uploading images/ ({count} files)...")
            ftp.cwd(remote_dir)
            try: ftp.cwd('images')
            except:
                try: ftp.mkd('images'); print_info("  Created: images/"); ftp.cwd('images')
                except Exception as e: print_error(f"Cannot create images/: {e}")
            u, f, s = ftp_upload_dir(ftp, local_images_dir, remote_dir + '/images', 'images')
            total_uploaded += u; total_failed += f
            print_success(f"  images/: {u} uploaded, {f} failed, {s} skipped")

        ftp.quit()
        print()
        print_success(f"Content deploy complete — {total_uploaded} files uploaded, {total_failed} failed")
        print_info("https://paulstamey.com/momsrecipes/")
        return db_failed == 0 and total_failed == 0

    except Exception as e:
        print_error(f"FTP content deploy failed: {e}")
        import traceback; traceback.print_exc()
        return False

# =============================================================================
# MAIN
# =============================================================================

def promote_to_production():
    """Copy all files from staging (ftp_remote_dir) to production (prod_dir) over FTP.
    
    Never touches api/data/ — the production database is preserved.
    Files are streamed through memory (download from staging, upload to prod)
    since FTP has no server-side copy command.
    
    Configure prod_dir in pipeline_config.json:
      "prod_dir": "/public_html/momsrecipes"
    """
    print_header("Promote Staging → Production")

    staging_dir = CONFIG.get('ftp_remote_dir', '').rstrip('/')
    prod_dir    = CONFIG.get('prod_dir', '').rstrip('/')

    if not staging_dir:
        print_error("ftp_remote_dir not set in config"); return False
    if not prod_dir:
        print_error("prod_dir not set in pipeline_config.json")
        print_info('Add:  "prod_dir": "/public_html/momsrecipes"  to pipeline_config.json')
        return False
    if staging_dir == prod_dir:
        print_error("staging and prod_dir are the same — nothing to promote")
        print_info(f"  staging: {staging_dir}")
        print_info(f"  prod:    {prod_dir}")
        return False

    print_info(f"Staging: {staging_dir}")
    print_info(f"Prod:    {prod_dir}")
    print_info("Skipping: api/data/  (production database preserved)")
    print()

    # Confirm before overwriting production
    try:
        confirm = input("  Type 'yes' to promote to production: ").strip().lower()
    except EOFError:
        confirm = ''
    if confirm != 'yes':
        print_warning("Promote cancelled")
        return False

    try:
        ftp = ftplib.FTP()
        ftp.connect(CONFIG['ftp_host'], 21, timeout=120)
        ftp.login(CONFIG['ftp_user'], CONFIG['ftp_pass'])
        ftp.set_pasv(True)
        print_success(f"Connected to {CONFIG['ftp_host']}")

        # Recursively collect all files under staging_dir
        def list_remote(ftp, remote_path):
            """Return list of (relative_path, full_remote_path) for every file under remote_path."""
            entries = []
            try:
                items = []
                ftp.retrlines(f'LIST {remote_path}', items.append)
            except Exception as e:
                print_warning(f"  Cannot list {remote_path}: {e}")
                return entries
            for line in items:
                parts = line.split(None, 8)
                if len(parts) < 9:
                    continue
                name = parts[8].strip()
                if name in ('.', '..'):
                    continue
                full = f"{remote_path}/{name}"
                rel  = full[len(staging_dir):].lstrip('/')
                if line.startswith('d'):
                    # Directory — skip api/data to protect production DB
                    if rel == 'api/data' or rel.startswith('api/data/'):
                        print_info(f"  Skipping {rel}/ (production DB protected)")
                        continue
                    entries.extend(list_remote(ftp, full))
                else:
                    entries.append((rel, full))
            return entries

        print_info("Scanning staging directory...")
        all_files = list_remote(ftp, staging_dir)
        print_info(f"Found {len(all_files)} files to promote")
        print()

        # Ensure prod_dir exists
        try:
            ftp.cwd(prod_dir)
        except:
            print_info(f"Creating prod dir: {prod_dir}")
            parts = prod_dir.strip('/').split('/')
            ftp.cwd('/')
            for part in parts:
                try: ftp.cwd(part)
                except:
                    ftp.mkd(part); ftp.cwd(part)

        promoted = 0; failed = 0

        for rel_path, src_full in all_files:
            dest_full = f"{prod_dir}/{rel_path}"
            dest_dir  = dest_full.rsplit('/', 1)[0]
            filename  = dest_full.rsplit('/', 1)[1]

            # Ensure destination subdirectory exists
            try:
                ftp.cwd(dest_dir)
            except:
                # Create each missing part
                parts = dest_dir.strip('/').split('/')
                ftp.cwd('/')
                for part in parts:
                    try: ftp.cwd(part)
                    except:
                        try: ftp.mkd(part); ftp.cwd(part)
                        except: pass

            # Download from staging into memory, upload to prod
            buf = io.BytesIO()
            try:
                ftp.retrbinary(f'RETR {src_full}', buf.write)
            except Exception as e:
                print_error(f"  Download failed: {rel_path}: {e}")
                failed += 1
                continue

            buf.seek(0)
            try:
                ftp.cwd(dest_dir)
                ftp.storbinary(f'STOR {filename}', buf)
                promoted += 1
                size_kb = buf.getbuffer().nbytes / 1024
                if size_kb > 50:
                    print_success(f"  {rel_path} ({size_kb:.1f} KB)")
                elif promoted % 50 == 0:
                    print_info(f"  {promoted}/{len(all_files)} files promoted...")
                # Keepalive every 30 files
                if promoted % 30 == 0:
                    try: ftp.voidcmd('NOOP')
                    except: pass
            except Exception as e:
                print_error(f"  Upload failed: {rel_path}: {e}")
                failed += 1

        ftp.quit()
        print()
        print_success(f"Promoted {promoted} files  |  Failed: {failed}")
        if failed == 0:
            print_success("Production is now up to date")
            print_info("https://paulstamey.com/momsrecipes/")
        else:
            print_warning(f"{failed} files failed — check errors above")
        return failed == 0

    except Exception as e:
        print_error(f"Promote failed: {e}")
        import traceback; traceback.print_exc()
        return False


# =============================================================================
def run_pipeline(steps, local=False):
    print(f"{Colors.BOLD}")
    print("╔═══════════════════════════════════════════════════════════════════╗")
    print("║          Recipe Processing & Deployment Pipeline v3.0            ║")
    print("╚═══════════════════════════════════════════════════════════════════╝")
    print(f"{Colors.ENDC}\n")

    if local and any(s in steps for s in ['deploy', 'deploy-frontend', 'deploy-api']):
        print_error("--local cannot be combined with deploy commands.")
        print_info("--local builds to output/website/frontend-local/ for local dev only.")
        return False

    needs_ftp = any(s in steps for s in ['deploy', 'deploy-frontend', 'deploy-api', 'backup-db', 'update-users', 'fix-server', 'update-db', 'promote', 'deploy-template'])
    if needs_ftp:
        if not load_config():
            print_error("Cannot deploy without valid configuration")
            return False

    success = True

    if 'setup' in steps:
        setup_credentials()
        return True

    if 'check' in steps:
        return check_connection()

    if 'process' in steps:
        if not process_recipes():
            print_error("Processing failed"); return False

    if 'build' in steps:
        if not build_output(local=local):
            print_error("Build failed"); return False

    if 'backup-db' in steps:
        backup_database()

    if 'deploy' in steps:
        if not deploy_to_server():
            print_error("Deployment failed"); return False

    if 'deploy-frontend' in steps:
        stamp_database()
        if not deploy_frontend():
            print_error("Frontend deploy failed"); return False

    if 'deploy-api' in steps:
        stamp_database()
        if not deploy_api():
            print_error("API deploy failed"); return False

    if 'deploy-template' in steps:
        if not deploy_template():
            print_error("Template deploy failed"); return False

    if 'update-users' in steps:
        if not update_approved_users():
            print_error("User list update failed")
            success = False

    if 'fix-server' in steps:
        if not fix_server():
            print_error("Server fix failed")
            success = False

    if 'update-db' in steps:
        if not update_database():
            print_error("Database update failed")
            success = False

    if 'promote' in steps:
        if not promote_to_production():
            print_error("Promote failed")
            success = False

    if 'serve' in steps:
        serve_local()  # Blocking — runs until Ctrl+C

    return success


def main():
    parser = argparse.ArgumentParser(
        description='Recipe Processing & Deployment Pipeline v3.0',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Build modes:
  --build                  Production build → output/website/frontend/
                             Auth ON, absolute API URL, cache-busting timestamps
  --build --local          Local dev build → output/website/frontend-local/
                             Auth BYPASSED, relative API URL, no cache-busting
                             Serve with: cd output/website/frontend-local/frontend
                                         python3 -m http.server 8000

Common workflows:
  python3 recipe_pipeline.py --build --deploy-frontend  # Build + ship to Namecheap
  python3 recipe_pipeline.py --build --local            # Build for local dev preview
  python3 recipe_pipeline.py --deploy                   # Full deploy (backup + frontend + API)
  python3 recipe_pipeline.py --deploy-frontend          # Deploy only HTML/CSS/JS
  python3 recipe_pipeline.py --deploy-api               # Deploy only API PHP files
  python3 recipe_pipeline.py --process                  # Process Word docs → recipe pages
  python3 recipe_pipeline.py --backup-db                # Download database backup
  python3 recipe_pipeline.py --update-db                # Push recipes to database
  python3 recipe_pipeline.py --all                      # Process → Build → Deploy → Update DB

Security:
  Credentials are loaded from pipeline_config.json (not hardcoded).
  The deploy NEVER uploads .db files — database is auto-created by PHP.
  Database is backed up before every deploy automatically.
        """
    )

    parser.add_argument('--setup',          action='store_true', help='Interactive credential setup (run once)')
    parser.add_argument('--check',          action='store_true', help='Verify FTP connection and remote structure')
    parser.add_argument('--build',          action='store_true', help='Build src/ → output/  (prod: auth on, absolute URL)')
    parser.add_argument('--local',          action='store_true', help='Modifier for --build: auth off, relative URL, no cache-bust → frontend-local/')
    parser.add_argument('--process',          action='store_true', help='Process recipes from Word docs')
    parser.add_argument('--process-imported', action='store_true', help='Generate HTML pages for DB-only imported recipes')
    parser.add_argument('--deploy',         action='store_true', help='Full deploy: backup → frontend → API')
    parser.add_argument('--deploy-frontend',action='store_true', help='Deploy built frontend to Namecheap (requires --build first)')
    parser.add_argument('--deploy-api',     action='store_true', help='Deploy only API PHP files')
    parser.add_argument('--deploy-template', action='store_true', help='Deploy dynamic recipe.php + .htaccess')
    parser.add_argument('--serve',           action='store_true', help='Build local dev site and launch PHP server on localhost:8000')
    parser.add_argument('--backup-db',      action='store_true', help='Download database backup from server')
    parser.add_argument('--update-db',      action='store_true', help='Deploy content: sync DB records + upload recipes/ and images/ via FTP')
    parser.add_argument('--fix-server',     action='store_true', help='Remove bad .htaccess from api/ (fixes 403 errors)')
    parser.add_argument('--update-users',   action='store_true', help='Upload approved_users.json to server')
    parser.add_argument('--promote',        action='store_true', help='Promote staging → production')
    parser.add_argument('--all',            action='store_true', help='Process → Build → Deploy → Update DB')
    parser.add_argument('--debug',          action='store_true', help='Show detailed tracebacks')

    args = parser.parse_args()

    # --local without --build is a no-op — catch it early
    if args.local and not args.build:
        print_error("--local must be used with --build")
        print_info("Example: python3 recipe_pipeline.py --build --local")
        sys.exit(1)

    if not any([args.setup, args.check, args.process, args.process_imported, args.build, args.deploy,
                args.deploy_frontend, args.deploy_api, args.deploy_template, args.serve,
                args.backup_db, args.update_db, args.update_users, args.all,
                args.fix_server, args.promote]):
        parser.print_help()
        sys.exit(0)

    steps = []
    if args.all:
        steps = ['process', 'build', 'deploy', 'update-db']
    else:
        if args.setup:           steps.append('setup')
        if args.check:           steps.append('check')
        if args.process:           steps.append('process')
        if args.process_imported:  steps.append('process-imported')
        if args.build:           steps.append('build')
        if args.backup_db:       steps.append('backup-db')
        if args.deploy:          steps.append('deploy')
        if args.deploy_frontend: steps.append('deploy-frontend')
        if args.deploy_api:      steps.append('deploy-api')
        if args.deploy_template: steps.append('deploy-template')
        if args.serve:            steps.append('serve')
        if args.update_users:    steps.append('update-users')
        if args.update_db:       steps.append('update-db')
        if args.fix_server:      steps.append('fix-server')
        if args.promote:         steps.append('promote')

    success = run_pipeline(steps, local=args.local)

    print_header("Pipeline Complete")
    if success:
        print(f"{Colors.OKGREEN}{Colors.BOLD}✓ All steps completed successfully!{Colors.ENDC}\n")
        if args.local:
            out_dir = os.path.join(CONFIG['output_dir'], 'website', 'frontend-local', 'frontend')
            print_info(f"Local build ready. Serve with:")
            print_info(f"  cd {out_dir} && python3 -m http.server 8000")
        elif any(s in steps for s in ['deploy', 'deploy-frontend']):
            print_info("Website: https://paulstamey.com/momsrecipes/")
        if 'update-db' in steps:
            print_info(f"API: {CONFIG['api_url']}/recipes")
        sys.exit(0)
    else:
        print(f"{Colors.FAIL}{Colors.BOLD}✗ Pipeline completed with errors{Colors.ENDC}\n")
        sys.exit(1)

if __name__ == '__main__':
    main()
