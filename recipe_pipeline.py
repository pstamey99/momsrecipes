#!/usr/bin/env python3
"""
Recipe Converter with Deployment Pipeline v2.0
Complete workflow: Process → Deploy → Update Database

New in v2.0:
  - Separate frontend/backend deploys (--deploy-frontend, --deploy-api)
  - Automatic database backup before any deploy
  - Deploy exclusion list (never uploads .db, .sqlite, config files)
  - External config file support (pipeline_config.json)
  - Security improvements (no hardcoded credentials)

Usage:
    python3 recipe_pipeline.py --process            # Process recipes and images
    python3 recipe_pipeline.py --deploy             # Deploy BOTH frontend + API
    python3 recipe_pipeline.py --deploy-frontend    # Deploy only HTML/CSS/JS/images
    python3 recipe_pipeline.py --deploy-api         # Deploy only api.php + .htaccess
    python3 recipe_pipeline.py --backup-db          # Download database backup only
    python3 recipe_pipeline.py --update-db          # Update API database with recipes
    python3 recipe_pipeline.py --all                # Process → Deploy → Update DB
"""

import os
import sys
import json
import argparse
import ftplib
import fnmatch
import requests
from pathlib import Path
from datetime import datetime

# =============================================================================
# CONFIGURATION
# =============================================================================

CONFIG = {
    'input_dir': './input',
    'output_dir': './output',
    'images_dir': './output/images',
    'ftp_host': 'ftp.paulstamey.com',
    'ftp_user': '',
    'ftp_pass': '',
    'ftp_remote_dir': '/public_html/momsrecipes',
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
    ftp.connect(CONFIG['ftp_host'], 21, timeout=30)
    ftp.login(CONFIG['ftp_user'], CONFIG['ftp_pass'])
    print_success(f"Connected to {CONFIG['ftp_host']}")

    ftp_root = ftp.pwd()
    print_info(f"FTP root: {ftp_root}")

    for path in [CONFIG['ftp_remote_dir'], '/public_html/momsrecipes', f'{ftp_root}/public_html/momsrecipes']:
        try:
            ftp.cwd(path)
            print_success(f"Found remote directory: {path}")
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
            print_success(f"Created: {rd}")
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
    print_success(f"Created: {rd}")
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

def deploy_frontend():
    """Deploy only frontend files. Skips api/ directory entirely."""
    print_header("Deploying Frontend")

    website_dir = os.path.join(CONFIG['output_dir'], 'website')
    if not os.path.exists(website_dir):
        print_error(f"Website not found: {website_dir}")
        print_info("Run --process first")
        return False

    try:
        ftp, remote_dir = ftp_connect()
        uploaded = 0; excluded = 0; failed = 0

        print_info("Uploading frontend (skipping api/ directory)...")
        print()

        for root, dirs, files in os.walk(website_dir):
            rel_path = os.path.relpath(root, website_dir)

            # Skip api/ entirely
            if rel_path == 'api' or rel_path.startswith('api/') or rel_path.startswith('api\\'):
                dirs[:] = []
                continue

            ftp.cwd(remote_dir)
            if rel_path != '.':
                for part in rel_path.replace('\\', '/').split('/'):
                    try: ftp.cwd(part)
                    except:
                        try:
                            ftp.mkd(part)
                            print_info(f"  Created: {part}/")
                            ftp.cwd(part)
                        except:
                            print_warning(f"  Could not create: {part}")

            for filename in files:
                if is_excluded(filename):
                    excluded += 1
                    continue
                local_file = os.path.join(root, filename)
                try:
                    with open(local_file, 'rb') as f:
                        ftp.storbinary(f'STOR {filename}', f)
                    uploaded += 1
                    sz = os.path.getsize(local_file) / 1024
                    if sz > 100:
                        print_success(f"  {filename} ({sz:.1f} KB)")
                    elif uploaded % 20 == 0:
                        print_info(f"  {uploaded} files uploaded...")
                except Exception as e:
                    print_error(f"  Failed: {filename}: {e}")
                    failed += 1

            ftp.cwd(remote_dir)

        ftp.quit()
        print()
        print_success(f"Frontend: {uploaded} uploaded, {excluded} excluded, {failed} failed")
        print_info("https://paulstamey.com/momsrecipes/")
        return failed == 0

    except Exception as e:
        print_error(f"Frontend deploy failed: {e}")
        import traceback; traceback.print_exc()
        return False


# =============================================================================
# DEPLOY API (api.php, .htaccess — never .db files)
# =============================================================================

def deploy_api():
    """Deploy only API backend files. Never uploads database files."""
    print_header("Deploying API Backend")

    api_source = None
    for d in [os.path.join(CONFIG['output_dir'], 'website', 'api'),
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
        try: ftp.cwd('api')
        except:
            try:
                ftp.mkd('api'); print_info("  Created: api/")
                ftp.cwd('api')
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
    """Update the API database with processed recipes."""
    print_header("Step 3: Updating Database")

    meta_file = os.path.join(CONFIG['output_dir'], 'recipes_metadata.json')
    if not os.path.exists(meta_file):
        print_error(f"Metadata not found: {meta_file}")
        print_info("Run --process first")
        return False

    try:
        with open(meta_file, 'r', encoding='utf-8') as f:
            recipes = json.load(f)

        print_info(f"Found {len(recipes)} recipes to update")
        created = 0; updated = 0; failed = 0; failed_recipes = []

        def normalize(field):
            if not field: return ''
            if isinstance(field, str): return field.strip()
            if isinstance(field, list): return '\n'.join(str(i).strip() for i in field if i)
            return str(field).strip()

        def ensure_field(data, field, fallback_field, fallback_msg):
            if not data.get(field) or data[field].strip() == '':
                other = data.get(fallback_field, '')
                if other and other.strip() and '1. No' not in other:
                    data[field] = f'1. No {field} provided - see {fallback_field}'
                else:
                    data[field] = f'1. No {field} listed'
                print_warning(f"  → '{data.get('title', '?')}' missing {field}, using placeholder")

        for idx, recipe in enumerate(recipes, 1):
            try:
                from urllib.parse import quote
                search_url = f"{CONFIG['api_url']}/recipes?search={quote(recipe['title'])}"
                resp = requests.get(search_url, timeout=10)

                if resp.status_code == 404:
                    existing = []
                elif resp.ok:
                    existing = resp.json().get('recipes', [])
                else:
                    failed += 1
                    print_error(f"API error: {recipe['title']} (status {resp.status_code})")
                    failed_recipes.append({'title': recipe['title'], 'op': 'search', 'status': resp.status_code, 'error': resp.text[:200]})
                    continue

                if existing:
                    # UPDATE
                    rid = existing[0]['id']
                    ensure_field(recipe, 'ingredients', 'directions', 'directions')
                    ensure_field(recipe, 'directions', 'ingredients', 'ingredients')
                    resp = requests.put(f"{CONFIG['api_url']}/recipes/{rid}", json=recipe,
                                        headers={'Content-Type': 'application/json'}, timeout=10)
                    if resp.ok:
                        updated += 1
                        if idx % 10 == 0: print_info(f"Progress: {idx}/{len(recipes)}...")
                    else:
                        failed += 1
                        print_error(f"Update failed: {recipe['title']} ({resp.status_code})")
                        failed_recipes.append({'title': recipe['title'], 'op': 'update', 'status': resp.status_code, 'error': resp.text[:200]})
                else:
                    # CREATE
                    rd = {
                        'title': recipe.get('title', 'Untitled'),
                        'category': recipe.get('category', ''),
                        'contributor': recipe.get('contributor', ''),
                        'servings': recipe.get('servings', ''),
                        'prep_time': recipe.get('prep_time', ''),
                        'cook_time': recipe.get('cook_time', ''),
                        'ingredients': normalize(recipe.get('ingredients', '')),
                        'directions': normalize(recipe.get('directions', '')),
                        'tags': recipe.get('tags', ''),
                        'notes': recipe.get('notes', ''),
                    }
                    ensure_field(rd, 'ingredients', 'directions', 'directions')
                    ensure_field(rd, 'directions', 'ingredients', 'ingredients')

                    if recipe.get('image_data') and len(recipe.get('image_data', '')) < 5_000_000:
                        rd['image_data'] = recipe['image_data']
                        rd['image_filename'] = recipe.get('image_filename', 'image.jpg')

                    resp = requests.post(f"{CONFIG['api_url']}/recipes", json=rd,
                                         headers={'Content-Type': 'application/json'}, timeout=30)
                    if resp.ok:
                        created += 1
                        if idx % 10 == 0: print_info(f"Progress: {idx}/{len(recipes)}...")
                    else:
                        failed += 1
                        try: detail = resp.json().get('error', resp.text[:200])
                        except: detail = resp.text[:200]
                        print_error(f"Create failed: {recipe['title']} ({resp.status_code}): {detail}")
                        failed_recipes.append({'title': recipe['title'], 'op': 'create', 'status': resp.status_code, 'error': detail})

            except requests.Timeout:
                failed += 1
                print_error(f"Timeout: {recipe.get('title', '?')}")
                failed_recipes.append({'title': recipe.get('title', '?'), 'op': 'timeout', 'error': 'timeout'})
            except Exception as e:
                failed += 1
                print_error(f"Error: {recipe.get('title', '?')}: {e}")
                failed_recipes.append({'title': recipe.get('title', '?'), 'op': 'exception', 'error': str(e)})

        print()
        print_success(f"Created: {created}  |  Updated: {updated}")
        if failed > 0:
            print_warning(f"Failed: {failed}")
            fail_file = os.path.join(CONFIG['output_dir'], 'failed_recipes.json')
            with open(fail_file, 'w', encoding='utf-8') as f:
                json.dump(failed_recipes, f, indent=2, ensure_ascii=False)
            print_info(f"Details: {fail_file}")

        return failed == 0

    except Exception as e:
        print_error(f"Database update failed: {e}")
        import traceback; traceback.print_exc()
        return False


# =============================================================================
# MAIN
# =============================================================================

def run_pipeline(steps):
    print(f"{Colors.BOLD}")
    print("╔═══════════════════════════════════════════════════════════════════╗")
    print("║          Recipe Processing & Deployment Pipeline v2.0            ║")
    print("╚═══════════════════════════════════════════════════════════════════╝")
    print(f"{Colors.ENDC}\n")

    needs_ftp = any(s in steps for s in ['deploy', 'deploy-frontend', 'deploy-api', 'backup-db', 'update-users'])
    if needs_ftp:
        if not load_config():
            print_error("Cannot deploy without valid configuration")
            return False

    success = True

    if 'process' in steps:
        if not process_recipes():
            print_error("Processing failed"); return False

    if 'backup-db' in steps:
        backup_database()

    if 'deploy' in steps:
        if not deploy_to_server():
            print_error("Deployment failed"); return False

    if 'deploy-frontend' in steps:
        print_info("Backing up database before frontend deploy...")
        backup_database()
        if not deploy_frontend():
            print_error("Frontend deploy failed"); return False

    if 'deploy-api' in steps:
        print_info("Backing up database before API deploy...")
        backup_database()
        if not deploy_api():
            print_error("API deploy failed"); return False

    if 'update-users' in steps:
        if not update_approved_users():
            print_error("User list update failed")
            success = False

    if 'update-db' in steps:
        if not update_database():
            print_error("Database update failed")
            success = False

    return success


def main():
    parser = argparse.ArgumentParser(
        description='Recipe Processing & Deployment Pipeline v2.0',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python3 recipe_pipeline.py --process              # Process recipes only
  python3 recipe_pipeline.py --deploy               # Full deploy (backup + frontend + API)
  python3 recipe_pipeline.py --deploy-frontend      # Deploy only HTML/CSS/JS/images
  python3 recipe_pipeline.py --deploy-api           # Deploy only api.php, .htaccess
  python3 recipe_pipeline.py --backup-db            # Download database backup
  python3 recipe_pipeline.py --update-db            # Push recipes to database
  python3 recipe_pipeline.py --update-users         # Update approved users list only
  python3 recipe_pipeline.py --all                  # Process → Deploy → Update DB

  python3 recipe_pipeline.py --process --deploy-frontend   # Process then frontend only
  python3 recipe_pipeline.py --deploy-api --update-db      # Update API code then push data

Security:
  Credentials are loaded from pipeline_config.json (not hardcoded).
  The deploy NEVER uploads .db files — database is auto-created by PHP.
  Database is backed up before every deploy automatically.
  Approved users are managed in approved_users.json (update with --update-users).
        """
    )

    parser.add_argument('--process', action='store_true', help='Process recipes from Word docs')
    parser.add_argument('--deploy', action='store_true', help='Full deploy: backup → frontend → API')
    parser.add_argument('--deploy-frontend', action='store_true', help='Deploy only frontend (HTML/CSS/JS)')
    parser.add_argument('--deploy-api', action='store_true', help='Deploy only API (api.php, .htaccess)')
    parser.add_argument('--backup-db', action='store_true', help='Download database backup from server')
    parser.add_argument('--update-db', action='store_true', help='Push recipes to API database')
    parser.add_argument('--update-users', action='store_true', help='Upload approved_users.json to server')
    parser.add_argument('--all', action='store_true', help='Process → Deploy → Update DB')
    parser.add_argument('--debug', action='store_true', help='Show detailed tracebacks')

    args = parser.parse_args()

    if not any([args.process, args.deploy, args.deploy_frontend, args.deploy_api,
                args.backup_db, args.update_db, args.update_users, args.all]):
        parser.print_help()
        sys.exit(0)

    steps = []
    if args.all:
        steps = ['process', 'deploy', 'update-db']
    else:
        if args.process:         steps.append('process')
        if args.backup_db:       steps.append('backup-db')
        if args.deploy:          steps.append('deploy')
        if args.deploy_frontend: steps.append('deploy-frontend')
        if args.deploy_api:      steps.append('deploy-api')
        if args.update_users:    steps.append('update-users')
        if args.update_db:       steps.append('update-db')

    success = run_pipeline(steps)

    print_header("Pipeline Complete")
    if success:
        print(f"{Colors.OKGREEN}{Colors.BOLD}✓ All steps completed successfully!{Colors.ENDC}\n")
        if any(s in steps for s in ['deploy', 'deploy-frontend']):
            print_info("Website: https://paulstamey.com/momsrecipes/")
        if 'update-db' in steps:
            print_info(f"API: {CONFIG['api_url']}/recipes")
        sys.exit(0)
    else:
        print(f"{Colors.FAIL}{Colors.BOLD}✗ Pipeline completed with errors{Colors.ENDC}\n")
        sys.exit(1)

if __name__ == '__main__':
    main()
