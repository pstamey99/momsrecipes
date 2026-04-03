#!/usr/bin/env python3
"""
Recipe Converter with Deployment Pipeline
Complete workflow: Process → Deploy → Update Database

Usage:
    python3 recipe_pipeline.py --process       # Process recipes and images only
    python3 recipe_pipeline.py --deploy        # Deploy processed files to server
    python3 recipe_pipeline.py --update-db     # Update API database with recipes
    python3 recipe_pipeline.py --all           # Do all steps in sequence
"""

import os
import sys
import json
import argparse
import ftplib
import requests
from pathlib import Path
from datetime import datetime

# =============================================================================
# CONFIGURATION
# =============================================================================

CONFIG = {
    # Processing
    'input_dir': './input',           # Where your Word docs / recipe files are
    'output_dir': './output',         # Where processed HTML goes
    'images_dir': './output/images',  # Where extracted images go
    
    # FTP Deployment
    'ftp_host': 'ftp.paulstamey.com',
    'ftp_user': 'paul.stamey',  # Updated
    'ftp_pass': 'your-ftp-password',  # UPDATE THIS
    'ftp_remote_dir': '/paul.stamey/public_html/momsrecipes',  # Updated with username
    
    # API Configuration
    'api_url': 'https://paulstamey.com/momsrecipes/api/index.php',
    'api_key': '3989d3f181341f17a5ed6b65c72b2ffc0df6343a4ca586bdea3ef0d149d4c130',
}

# =============================================================================
# COLORS FOR OUTPUT
# =============================================================================

class Colors:
    HEADER = '\033[95m'
    OKBLUE = '\033[94m'
    OKCYAN = '\033[96m'
    OKGREEN = '\033[92m'
    WARNING = '\033[93m'
    FAIL = '\033[91m'
    ENDC = '\033[0m'
    BOLD = '\033[1m'

def print_header(message):
    print(f"\n{Colors.HEADER}{Colors.BOLD}{'=' * 70}{Colors.ENDC}")
    print(f"{Colors.HEADER}{Colors.BOLD}{message.center(70)}{Colors.ENDC}")
    print(f"{Colors.HEADER}{Colors.BOLD}{'=' * 70}{Colors.ENDC}\n")

def print_success(message):
    print(f"{Colors.OKGREEN}✓ {message}{Colors.ENDC}")

def print_error(message):
    print(f"{Colors.FAIL}✗ {message}{Colors.ENDC}")

def print_warning(message):
    print(f"{Colors.WARNING}⚠ {message}{Colors.ENDC}")

def print_info(message):
    print(f"{Colors.OKCYAN}ℹ {message}{Colors.ENDC}")

# =============================================================================
# STEP 1: PROCESS RECIPES AND IMAGES
# =============================================================================

def process_recipes():
    """
    Process Word documents using the existing recipe_converter_namecheap.py
    Each file can contain MULTIPLE recipes
    """
    print_header("Step 1: Processing Recipes and Images")
    
    # Check if input directory exists
    if not os.path.exists(CONFIG['input_dir']):
        print_error(f"Input directory not found: {CONFIG['input_dir']}")
        print_info("Create the directory and add your recipe files (Word docs, PDFs, etc.)")
        return False
    
    # Create output directories
    os.makedirs(CONFIG['output_dir'], exist_ok=True)
    os.makedirs(CONFIG['images_dir'], exist_ok=True)
    
    try:
        # Import the recipe converter
        print_info("Loading recipe converter...")
        
        # Check if converter exists
        converter_file = 'recipe_converter_namecheap.py'
        if not os.path.exists(converter_file):
            print_error(f"Recipe converter not found: {converter_file}")
            print_info("Please copy recipe_converter_namecheap.py to this directory")
            return False
        
        # Import the necessary classes
        import sys
        sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
        
        from recipe_converter_namecheap import RecipeParser
        
        print_success("Recipe converter loaded successfully")
        
        # Find all recipe files recursively
        print_info("Scanning for Word documents (including subdirectories)...")
        
        recipe_files = []
        extensions = ['.docx', '.doc']
        
        for ext in extensions:
            files = list(Path(CONFIG['input_dir']).rglob(f'*{ext}'))
            # Filter out temp files
            files = [f for f in files if not f.name.startswith('~$')]
            recipe_files.extend(files)
        
        if not recipe_files:
            print_warning(f"No Word documents found in {CONFIG['input_dir']}")
            print_info("Add .docx files to process")
            print_info("Files can be in subdirectories - they will be found recursively")
            return False
        
        print_success(f"Found {len(recipe_files)} Word documents to process")
        
        # Show folder structure
        folders = set()
        for f in recipe_files:
            rel_path = f.relative_to(CONFIG['input_dir'])
            if len(rel_path.parts) > 1:
                folders.add(rel_path.parent)
        
        if folders:
            print_info(f"Files found in {len(folders)} subdirectories:")
            for folder in sorted(folders):
                count = sum(1 for f in recipe_files if folder in f.relative_to(CONFIG['input_dir']).parents or f.parent.relative_to(CONFIG['input_dir']) == folder)
                print(f"  📁 {folder}/ ({count} files)")
        
        # Process each file - extracting MULTIPLE recipes per file
        parser = RecipeParser()
        all_recipes = []
        total_files = len(recipe_files)
        recipe_count_by_file = {}
        
        print()
        for idx, recipe_file in enumerate(recipe_files, 1):
            rel_path = recipe_file.relative_to(CONFIG['input_dir'])
            
            print_info(f"[{idx}/{total_files}] Processing: {rel_path}")
            
            # Determine category from folder structure
            if rel_path.parent != Path('.'):
                category = str(rel_path.parent)
            else:
                category = 'Uncategorized'
            
            try:
                # Parse the document to extract ALL recipes
                recipes_from_file = parser.parse_document(str(recipe_file))
                
                if recipes_from_file:
                    # Convert recipes to database format and add metadata
                    valid_recipes = 0
                    for recipe in recipes_from_file:
                        # Check if recipe has actual content
                        has_ingredients = bool(recipe.get('ingredients'))
                        has_directions = bool(recipe.get('directions'))
                        
                        if not has_ingredients and not has_directions:
                            print_warning(f"    ⚠ Skipping '{recipe.get('title', 'Untitled')}' - no ingredients or directions")
                            continue
                        
                        # Convert recipe to API format
                        api_recipe = {
                            'title': recipe.get('title', 'Untitled'),
                            'category': category,
                            'contributor': recipe.get('family_source', recipe.get('contributor', 'Unknown')),
                            'servings': recipe.get('servings', ''),
                            'prep_time': recipe.get('prep_time', ''),
                            'cook_time': recipe.get('cook_time', ''),
                            'ingredients': '\n'.join(recipe.get('ingredients', [])),
                            'directions': '\n'.join(recipe.get('directions', [])),  # Using 'directions' everywhere now
                            'tags': recipe.get('tags', ''),
                            'notes': '\n'.join(recipe.get('notes', [])),
                            'source_file': str(recipe_file),
                            'relative_path': str(rel_path),
                        }
                        
                        # Add image data if available
                        if recipe.get('image_path'):
                            try:
                                with open(recipe['image_path'], 'rb') as img_file:
                                    import base64
                                    image_data = base64.b64encode(img_file.read()).decode('utf-8')
                                    api_recipe['image_data'] = f"data:image/jpeg;base64,{image_data}"
                                    api_recipe['image_filename'] = os.path.basename(recipe['image_path'])
                            except:
                                pass
                        
                        all_recipes.append(api_recipe)
                        valid_recipes += 1
                    
                    recipe_count_by_file[recipe_file.name] = valid_recipes
                    
                    if valid_recipes > 0:
                        print_success(f"  → Extracted {valid_recipes} valid recipes from {recipe_file.name}")
                    else:
                        print_warning(f"  → No valid recipes found in {recipe_file.name}")
                        
                    # Show if some were skipped
                    skipped = len(recipes_from_file) - valid_recipes
                    if skipped > 0:
                        print_warning(f"    ({skipped} recipes skipped due to missing content)")
                else:
                    print_warning(f"  → No recipes found in {recipe_file.name}")
                    
            except Exception as e:
                print_error(f"  → Failed to process {recipe_file.name}: {e}")
                print_warning(f"  → Skipping this file")
                import traceback
                if '--debug' in sys.argv:
                    traceback.print_exc()
        
        if not all_recipes:
            print_error("\nNo recipes were extracted from any files")
            return False
        
        # Generate HTML website files
        print()
        print_info("Generating HTML website files...")
        try:
            from recipe_converter_namecheap import HTMLGenerator
            
            # Create website directory structure
            website_dir = os.path.join(CONFIG['output_dir'], 'website')
            html_gen = HTMLGenerator(website_dir)
            
            # Convert our API format back to converter format for HTML generation
            converter_recipes = []
            for api_recipe in all_recipes:
                converter_recipe = {
                    'title': api_recipe['title'],
                    'ingredients': api_recipe['ingredients'].split('\n') if api_recipe['ingredients'] else [],
                    'directions': api_recipe['directions'].split('\n') if api_recipe['directions'] else [],  # Fixed: use 'directions' not 'instructions'
                    'family_source': api_recipe['contributor'],
                    'notes': api_recipe['notes'].split('\n') if api_recipe['notes'] else [],
                    'tags': api_recipe.get('tags', ''),
                    'servings': api_recipe.get('servings', ''),
                    'prep_time': api_recipe.get('prep_time', ''),
                    'cook_time': api_recipe.get('cook_time', ''),
                }
                converter_recipes.append(converter_recipe)
            
            # Generate the website
            html_gen.generate_website(converter_recipes)
            
            print_success(f"Website generated at: {website_dir}")
            print_info(f"  Main page: {website_dir}/index.html")
            print_info(f"  Recipes page: {website_dir}/momsrecipes/index.html")
            
        except Exception as e:
            print_warning(f"HTML generation failed: {e}")
            print_info("Continuing without HTML files (metadata still saved)")
            if '--debug' in sys.argv:
                import traceback
                traceback.print_exc()
        
        # Save recipe metadata for later database update
        metadata_file = os.path.join(CONFIG['output_dir'], 'recipes_metadata.json')
        with open(metadata_file, 'w', encoding='utf-8') as f:
            json.dump(all_recipes, f, indent=2, ensure_ascii=False)
        
        # Summary
        print()
        print_success(f"Saved recipe metadata to: {metadata_file}")
        print_success(f"Total recipes extracted: {len(all_recipes)} from {total_files} files")
        
        if total_files > 0:
            avg = len(all_recipes) / total_files
            print_info(f"Average: {avg:.1f} recipes per file")
        
        # Show if there's a discrepancy
        print()
        print_info(f"Recipe extraction summary:")
        print(f"  Files processed: {total_files}")
        print(f"  Recipes found (with Heading 1): Check your Word docs")
        print(f"  Recipes extracted (with content): {len(all_recipes)}")
        print()
        print_warning("Note: Recipes are only counted if they have ingredients OR directions")
        print_warning("If you're expecting more recipes, check your Word documents for:")
        print("  1. Recipe titles must be Heading 1 style")
        print("  2. Recipes must have ingredients or directions")
        print("  3. Check if some titles are being filtered (see warnings above)")
        print()
        print_info("Field name standardization:")
        print("  - Word docs: 'directions'")
        print("  - Database: 'directions'")
        print("  - API: 'directions'")
        print("  - Everything now uses 'directions' consistently ✓")
        
        # Show top files by recipe count
        if recipe_count_by_file:
            print()
            print_info("Top files by recipe count:")
            sorted_files = sorted(recipe_count_by_file.items(), key=lambda x: -x[1])
            for filename, count in sorted_files[:10]:
                print(f"  {filename}: {count} recipes")
        
        # Show summary by category
        categories = {}
        for recipe in all_recipes:
            cat = recipe.get('category', 'Uncategorized')
            categories[cat] = categories.get(cat, 0) + 1
        
        if categories:
            print()
            print_info("Recipes by category:")
            for cat, count in sorted(categories.items(), key=lambda x: -x[1]):
                print(f"  {cat}: {count} recipes")
        
        return True
        
    except ImportError as e:
        print_error(f"Failed to import recipe converter: {e}")
        print_info("Make sure recipe_converter_namecheap.py is in the same directory")
        print_info("Required: pip3 install python-docx Pillow lxml")
        return False
    except Exception as e:
        print_error(f"Processing failed: {e}")
        import traceback
        traceback.print_exc()
        return False

# =============================================================================
# STEP 2: DEPLOY TO SERVER
# =============================================================================

def deploy_to_server():
    """
    Deploy processed HTML files and images to server via FTP
    """
    print_header("Step 2: Deploying to Server")
    
    # Check if output exists
    website_dir = os.path.join(CONFIG['output_dir'], 'website')
    
    if not os.path.exists(website_dir):
        print_error(f"Website directory not found: {website_dir}")
        print_info("Run --process first to create website files")
        print_info("The process step generates HTML files in output/website/")
        return False
    
    # Connect to FTP
    print_info(f"Connecting to {CONFIG['ftp_host']}...")
    
    try:
        ftp = ftplib.FTP()
        ftp.connect(CONFIG['ftp_host'], 21, timeout=30)
        ftp.login(CONFIG['ftp_user'], CONFIG['ftp_pass'])
        print_success(f"Connected to {CONFIG['ftp_host']}")
        
        # Change to remote directory
        try:
            ftp.cwd(CONFIG['ftp_remote_dir'])
        except:
            print_info(f"Creating remote directory: {CONFIG['ftp_remote_dir']}")
            ftp.mkd(CONFIG['ftp_remote_dir'])
            ftp.cwd(CONFIG['ftp_remote_dir'])
        
        print_success(f"Changed to: {CONFIG['ftp_remote_dir']}")
        
        # Upload files
        uploaded = 0
        failed = 0
        
        print_info("Uploading website files...")
        
        for root, dirs, files in os.walk(website_dir):
            # Get relative path from website dir
            rel_path = os.path.relpath(root, website_dir)
            
            # Create remote directories
            if rel_path != '.':
                remote_path = rel_path.replace('\\', '/')
                path_parts = remote_path.split('/')
                
                # Create nested directories
                current_path = CONFIG['ftp_remote_dir']
                for part in path_parts:
                    current_path = current_path.rstrip('/') + '/' + part
                    try:
                        ftp.mkd(current_path)
                        print_info(f"Created directory: {part}/")
                    except:
                        pass  # Directory might already exist
                
                # Change to the directory
                try:
                    ftp.cwd(current_path)
                except:
                    print_warning(f"Could not change to: {remote_path}")
                    continue
            
            # Upload files in this directory
            for filename in files:
                local_file = os.path.join(root, filename)
                file_size = os.path.getsize(local_file)
                file_size_kb = file_size / 1024
                
                try:
                    with open(local_file, 'rb') as f:
                        ftp.storbinary(f'STOR {filename}', f)
                    uploaded += 1
                    
                    # Show progress for larger files
                    if file_size_kb > 100:
                        print_success(f"Uploaded: {filename} ({file_size_kb:.1f} KB)")
                    elif uploaded % 10 == 0:
                        print_info(f"Uploaded {uploaded} files...")
                        
                except Exception as e:
                    print_error(f"Failed to upload {filename}: {e}")
                    failed += 1
            
            # Return to base directory
            ftp.cwd(CONFIG['ftp_remote_dir'])
        
        ftp.quit()
        
        print()
        print_success(f"Uploaded {uploaded} files")
        if failed > 0:
            print_warning(f"Failed to upload {failed} files")
        
        print()
        print_info("Your website is now live!")
        print(f"  Main site: https://paulstamey.com/momsrecipes/")
        print(f"  Recipes: https://paulstamey.com/momsrecipes/momsrecipes/")
        
        return failed == 0
        
    except Exception as e:
        print_error(f"Deployment failed: {e}")
        import traceback
        traceback.print_exc()
        return False

# =============================================================================
# STEP 3: UPDATE DATABASE VIA API
# =============================================================================

def update_database():
    """
    Update the API database with processed recipes
    """
    print_header("Step 3: Updating Database")
    
    # Load recipe metadata
    metadata_file = os.path.join(CONFIG['output_dir'], 'recipes_metadata.json')
    
    if not os.path.exists(metadata_file):
        print_error(f"Recipe metadata not found: {metadata_file}")
        print_info("Run --process first to create recipe data")
        return False
    
    try:
        with open(metadata_file, 'r', encoding='utf-8') as f:
            recipes = json.load(f)
        
        print_info(f"Found {len(recipes)} recipes to update")
        
        # Update each recipe via API
        created = 0
        updated = 0
        failed = 0
        failed_recipes = []
        
        for idx, recipe in enumerate(recipes, 1):
            try:
                # Check if recipe exists (by title)
                search_url = f"{CONFIG['api_url']}/recipes?search={recipe['title']}"
                response = requests.get(search_url, timeout=10)
                
                if response.ok:
                    data = response.json()
                    existing_recipes = data.get('recipes', [])
                    
                    if existing_recipes:
                        # Recipe exists - update it
                        recipe_id = existing_recipes[0]['id']
                        update_url = f"{CONFIG['api_url']}/recipes/{recipe_id}"
                        
                        response = requests.put(
                            update_url,
                            json=recipe,
                            headers={'Content-Type': 'application/json'},
                            timeout=10
                        )
                        
                        if response.ok:
                            updated += 1
                            if idx % 10 == 0:
                                print_info(f"Progress: {idx}/{len(recipes)} recipes processed...")
                        else:
                            failed += 1
                            error_detail = response.text[:200]
                            print_error(f"Failed to update: {recipe['title']}")
                            print(f"  Status: {response.status_code}")
                            print(f"  Error: {error_detail}")
                            
                            # Debug: show source file info
                            if recipe.get('source_file'):
                                print(f"  Source: {os.path.basename(recipe['source_file'])}")
                            
                            failed_recipes.append({
                                'title': recipe['title'],
                                'source_file': recipe.get('source_file', 'Unknown'),
                                'category': recipe.get('category', 'Unknown'),
                                'operation': 'update',
                                'status': response.status_code,
                                'error': error_detail
                            })
                    else:
                        # Recipe doesn't exist - create it
                        create_url = f"{CONFIG['api_url']}/recipes"
                        
                        # Prepare recipe data - remove any problematic fields
                        recipe_data = {
                            'title': recipe.get('title', 'Untitled'),
                            'category': recipe.get('category', ''),
                            'contributor': recipe.get('contributor', ''),
                            'servings': recipe.get('servings', ''),
                            'prep_time': recipe.get('prep_time', ''),
                            'cook_time': recipe.get('cook_time', ''),
                            'ingredients': recipe.get('ingredients', ''),
                            'directions': recipe.get('directions', ''),  # Now using 'directions' everywhere
                            'tags': recipe.get('tags', ''),
                            'notes': recipe.get('notes', ''),
                        }
                        
                        # Only add image data if it exists and isn't too large
                        if recipe.get('image_data') and len(recipe.get('image_data', '')) < 5000000:  # 5MB limit
                            recipe_data['image_data'] = recipe['image_data']
                            recipe_data['image_filename'] = recipe.get('image_filename', 'image.jpg')
                        
                        response = requests.post(
                            create_url,
                            json=recipe_data,
                            headers={'Content-Type': 'application/json'},
                            timeout=30  # Longer timeout for images
                        )
                        
                        if response.ok:
                            created += 1
                            if idx % 10 == 0:
                                print_info(f"Progress: {idx}/{len(recipes)} recipes processed...")
                        else:
                            failed += 1
                            try:
                                error_data = response.json()
                                error_detail = error_data.get('error', response.text[:200])
                            except:
                                error_detail = response.text[:200]
                            
                            print_error(f"Failed to create: {recipe['title']}")
                            print(f"  Status: {response.status_code}")
                            print(f"  Error: {error_detail}")
                            
                            # Debug: show source file info
                            if recipe.get('source_file'):
                                print(f"  Source: {os.path.basename(recipe['source_file'])}")
                            
                            # Check for common issues
                            if response.status_code == 413:
                                print_warning(f"  → Recipe may have image that's too large")
                            elif response.status_code == 400:
                                print_warning(f"  → Recipe may have invalid data")
                            
                            failed_recipes.append({
                                'title': recipe['title'],
                                'source_file': recipe.get('source_file', 'Unknown'),
                                'category': recipe.get('category', 'Unknown'),
                                'operation': 'create',
                                'status': response.status_code,
                                'error': error_detail
                            })
                else:
                    failed += 1
                    print_error(f"API search error for: {recipe['title']}")
                    print(f"  Status: {response.status_code}")
                    failed_recipes.append({
                        'title': recipe['title'],
                        'source_file': recipe.get('source_file', 'Unknown'),
                        'category': recipe.get('category', 'Unknown'),
                        'operation': 'search',
                        'status': response.status_code,
                        'error': response.text[:200]
                    })
                    
            except requests.Timeout:
                failed += 1
                print_error(f"Timeout processing: {recipe.get('title', 'Unknown')}")
                print_warning("  → Request took too long (may have large image)")
                failed_recipes.append({
                    'title': recipe.get('title', 'Unknown'),
                    'source_file': recipe.get('source_file', 'Unknown'),
                    'category': recipe.get('category', 'Unknown'),
                    'operation': 'timeout',
                    'error': 'Request timeout'
                })
            except Exception as e:
                failed += 1
                print_error(f"Error processing {recipe.get('title', 'Unknown')}: {e}")
                failed_recipes.append({
                    'title': recipe.get('title', 'Unknown'),
                    'source_file': recipe.get('source_file', 'Unknown'),
                    'category': recipe.get('category', 'Unknown'),
                    'operation': 'exception',
                    'error': str(e)
                })
        
        # Summary
        print()
        print_success(f"Created: {created} recipes")
        print_success(f"Updated: {updated} recipes")
        if failed > 0:
            print_warning(f"Failed: {failed} recipes")
            
            # Save failed recipes for review
            if failed_recipes:
                failed_file = os.path.join(CONFIG['output_dir'], 'failed_recipes.json')
                with open(failed_file, 'w', encoding='utf-8') as f:
                    json.dump(failed_recipes, f, indent=2, ensure_ascii=False)
                print()
                print_warning(f"Failed recipes saved to: {failed_file}")
                print_info("Common issues:")
                print("  - Images too large (>5MB)")
                print("  - Special characters in text")
                print("  - Missing required fields")
                print("  - API server errors")
                print()
                print_info("To retry failed recipes:")
                print("  1. Review failed_recipes.json")
                print("  2. Fix issues in source Word documents")
                print("  3. Re-run: python3 recipe_pipeline.py --process --update-db")
        
        return failed == 0
        
    except Exception as e:
        print_error(f"Database update failed: {e}")
        import traceback
        traceback.print_exc()
        return False

# =============================================================================
# MAIN EXECUTION
# =============================================================================

def run_pipeline(steps):
    """
    Run the complete pipeline with specified steps
    """
    print(f"{Colors.BOLD}")
    print("╔═══════════════════════════════════════════════════════════════════╗")
    print("║          Recipe Processing & Deployment Pipeline v1.0            ║")
    print("╚═══════════════════════════════════════════════════════════════════╝")
    print(f"{Colors.ENDC}\n")
    
    success = True
    
    if 'process' in steps:
        if not process_recipes():
            print_error("Processing failed - stopping pipeline")
            return False
    
    if 'deploy' in steps:
        if not deploy_to_server():
            print_error("Deployment failed - stopping pipeline")
            return False
    
    if 'update-db' in steps:
        if not update_database():
            print_error("Database update failed")
            success = False
    
    return success

def main():
    parser = argparse.ArgumentParser(
        description='Recipe Processing & Deployment Pipeline',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python3 recipe_pipeline.py --process        # Process recipes only
  python3 recipe_pipeline.py --deploy         # Deploy to server only
  python3 recipe_pipeline.py --update-db      # Update database only
  python3 recipe_pipeline.py --all            # Do everything
  
  python3 recipe_pipeline.py --process --deploy  # Process and deploy (no DB update)
        """
    )
    
    parser.add_argument('--process', action='store_true',
                       help='Process recipes and extract images')
    parser.add_argument('--deploy', action='store_true',
                       help='Deploy files to server via FTP')
    parser.add_argument('--update-db', action='store_true',
                       help='Update API database with recipes')
    parser.add_argument('--all', action='store_true',
                       help='Run all steps: process → deploy → update-db')
    
    args = parser.parse_args()
    
    # If no arguments, show help
    if not (args.process or args.deploy or args.update_db or args.all):
        parser.print_help()
        sys.exit(0)
    
    # Determine which steps to run
    steps = []
    if args.all:
        steps = ['process', 'deploy', 'update-db']
    else:
        if args.process:
            steps.append('process')
        if args.deploy:
            steps.append('deploy')
        if args.update_db:
            steps.append('update-db')
    
    # Run pipeline
    success = run_pipeline(steps)
    
    # Final summary
    print_header("Pipeline Complete")
    
    if success:
        print(f"{Colors.OKGREEN}{Colors.BOLD}✓ All steps completed successfully!{Colors.ENDC}\n")
        
        if 'deploy' in steps:
            print_info("Your website is live at:")
            print(f"  https://paulstamey.com/momsrecipes/\n")
        
        if 'update-db' in steps:
            print_info("View your recipes via API:")
            print(f"  {CONFIG['api_url']}/recipes\n")
        
        sys.exit(0)
    else:
        print(f"{Colors.FAIL}{Colors.BOLD}✗ Pipeline completed with errors{Colors.ENDC}\n")
        sys.exit(1)

if __name__ == '__main__':
    main()
