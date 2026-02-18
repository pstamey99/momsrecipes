#!/usr/bin/env python3
"""
Unified Deployment Script with Environment Switching
Deploys to TEST or PRODUCTION based on env_config.py

Usage:
    python3 deploy.py --backend     # Deploy backend API only
    python3 deploy.py --frontend    # Deploy frontend website only
    python3 deploy.py --full        # Deploy everything
    python3 deploy.py --verify      # Test deployment
    
Switch environments by editing env_config.py:
    CURRENT_ENVIRONMENT = 'test'        # For testing
    CURRENT_ENVIRONMENT = 'production'  # For live site
"""

import ftplib
import os
import sys
import time
import requests
from pathlib import Path

# Import environment configuration
try:
    from env_config import get_config, CURRENT_ENVIRONMENT
except ImportError:
    print("Error: env_config.py not found!")
    print("Make sure env_config.py is in the same directory")
    sys.exit(1)

class Colors:
    OKGREEN = '\033[92m'
    WARNING = '\033[93m'
    FAIL = '\033[91m'
    ENDC = '\033[0m'
    BOLD = '\033[1m'
    BLUE = '\033[94m'

def print_success(msg):
    print(f"{Colors.OKGREEN}✓ {msg}{Colors.ENDC}")

def print_error(msg):
    print(f"{Colors.FAIL}✗ {msg}{Colors.ENDC}")

def print_warning(msg):
    print(f"{Colors.WARNING}⚠ {msg}{Colors.ENDC}")

def print_info(msg):
    print(f"{Colors.BLUE}ℹ {msg}{Colors.ENDC}")

def print_header(msg):
    print(f"\n{Colors.BOLD}{'='*70}{Colors.ENDC}")
    print(f"{Colors.BOLD}{msg}{Colors.ENDC}")
    print(f"{Colors.BOLD}{'='*70}{Colors.ENDC}\n")

def deploy_backend(config):
    """Deploy backend API files"""
    print_header(f"Deploying Backend to {config['env_name']}")
    
    # Files to upload
    api_files = [
        'api/config.php',
        'api/database.php',
        'api/index.php',
        'api/helpers.php',
        'api/test.html',
        'api/import-tool.html',
        'api/.htaccess'
    ]
    
    # Check local files exist
    missing = [f for f in api_files if not os.path.exists(f)]
    if missing:
        print_warning(f"Missing files: {', '.join(missing)}")
    
    try:
        # Connect to FTP
        print_info(f"Connecting to {config['ftp_host']}...")
        ftp = ftplib.FTP()
        ftp.connect(config['ftp_host'], config['ftp_port'], timeout=config['ftp_timeout'])
        ftp.login(config['ftp_user'], config['ftp_pass'])
        print_success(f"Connected to {config['ftp_host']}")
        
        # Create directory structure
        remote_api = config['ftp_api']
        print_info(f"Creating directory: {remote_api}")
        
        dirs = remote_api.strip('/').split('/')
        current = ''
        for dir_name in dirs:
            current += '/' + dir_name
            try:
                ftp.cwd(current)
                print_info(f"  ✓ Found: {current}")
            except:
                try:
                    ftp.mkd(current)
                    print_success(f"  → Created: {current}")
                    ftp.cwd(current)
                except Exception as e:
                    print_warning(f"  → Could not create {current}: {e}")
        
        print_success(f"In directory: {remote_api}")
        
        # Create data directory
        try:
            ftp.mkd('data')
            print_success("Created data/ directory")
        except:
            print_info("data/ directory already exists")
        
        # Upload .htaccess to protect data directory
        data_htaccess = '.htaccess-data'
        if os.path.exists(data_htaccess):
            print_info("Uploading data directory protection...")
            ftp.cwd('data')
            with open(data_htaccess, 'rb') as f:
                ftp.storbinary('STOR .htaccess', f)
            print_success("  → Protected data/ directory")
            ftp.cwd('..')  # Back to api directory
        
        # Upload parent momsrecipes .htaccess
        parent_htaccess = '.htaccess-momsrecipes'
        if os.path.exists(parent_htaccess):
            print_info("Uploading parent directory .htaccess...")
            ftp.cwd('..')  # Up to momsrecipes
            with open(parent_htaccess, 'rb') as f:
                ftp.storbinary('STOR .htaccess', f)
            print_success("  → Configured parent directory")
            ftp.cwd('api')  # Back to api directory
        
        # Upload files
        print()
        print_info("Uploading backend files...")
        uploaded = 0
        
        for file in api_files:
            if os.path.exists(file):
                filename = os.path.basename(file)
                print_info(f"  Uploading {filename}...")
                
                with open(file, 'rb') as f:
                    ftp.storbinary(f'STOR {filename}', f)
                
                print_success(f"    → Uploaded {filename}")
                uploaded += 1
        
        ftp.quit()
        
        print()
        print_success(f"Backend deployed! Uploaded {uploaded} files")
        
        # Show URLs
        print()
        print_info("Backend URLs:")
        print(f"  API Endpoint: {config['api_url']}")
        print(f"  Test Page: {config['test_page']}")
        
        return True
        
    except Exception as e:
        print_error(f"Backend deployment failed: {e}")
        import traceback
        traceback.print_exc()
        return False

def deploy_frontend(config):
    """Deploy frontend website files"""
    print_header(f"Deploying Frontend to {config['env_name']}")
    
    website_dir = 'output/website'
    
    if not os.path.exists(website_dir):
        print_error(f"Website directory not found: {website_dir}")
        print_info("Run: python3 recipe_pipeline.py --process")
        return False
    
    try:
        # Connect to FTP
        print_info(f"Connecting to {config['ftp_host']}...")
        ftp = ftplib.FTP()
        ftp.connect(config['ftp_host'], config['ftp_port'], timeout=config['ftp_timeout'])
        ftp.login(config['ftp_user'], config['ftp_pass'])
        print_success(f"Connected to {config['ftp_host']}")
        
        # Create directory structure
        remote_website = config['ftp_website']
        print_info(f"Creating directory: {remote_website}")
        
        dirs = remote_website.strip('/').split('/')
        current = ''
        for dir_name in dirs:
            current += '/' + dir_name
            try:
                ftp.cwd(current)
            except:
                try:
                    ftp.mkd(current)
                    print_success(f"  → Created: {current}")
                    ftp.cwd(current)
                except:
                    pass
        
        print_success(f"In directory: {remote_website}")
        
        # Upload files recursively
        print()
        print_info("Uploading website files...")
        uploaded = 0
        skipped = 0
        
        for root, dirs, files in os.walk(website_dir):
            # Get relative path from website_dir
            rel_path = os.path.relpath(root, website_dir)
            
            # Skip api directory - that's handled by backend deployment
            if 'api' in rel_path.split(os.sep):
                continue
            
            # Create remote directories
            if rel_path != '.':
                remote_path = rel_path.replace('\\', '/')
                path_parts = remote_path.split('/')
                
                current_path = remote_website
                for part in path_parts:
                    current_path = current_path.rstrip('/') + '/' + part
                    try:
                        ftp.mkd(current_path)
                    except:
                        pass
                
                try:
                    ftp.cwd(current_path)
                except:
                    continue
            else:
                ftp.cwd(remote_website)
            
            # Upload files in this directory
            for filename in files:
                local_file = os.path.join(root, filename)
                
                try:
                    with open(local_file, 'rb') as f:
                        ftp.storbinary(f'STOR {filename}', f)
                    uploaded += 1
                    
                    if uploaded % 20 == 0:
                        print_info(f"    Uploaded {uploaded} files...")
                        
                except Exception as e:
                    print_warning(f"  Skipped {filename}: {str(e)[:50]}")
                    skipped += 1
            
            # Return to base directory
            ftp.cwd(remote_website)
        
        ftp.quit()
        
        print()
        print_success(f"Frontend deployed! Uploaded {uploaded} files")
        if skipped > 0:
            print_warning(f"Skipped {skipped} files")
        
        # Show URLs
        print()
        print_info("Frontend URLs:")
        print(f"  Main Site: {config['website_url']}")
        print(f"  Recipe Browser: {config['website_url']}/momsrecipes/")
        
        return True
        
    except Exception as e:
        print_error(f"Frontend deployment failed: {e}")
        import traceback
        traceback.print_exc()
        return False

def verify_deployment(config):
    """Verify the deployment is working"""
    print_header("Verifying Deployment")
    
    api_url = config['api_url']
    
    print_info(f"Testing API at: {api_url}")
    try:
        response = requests.get(api_url, timeout=10)
        if response.ok:
            print_success("API is responding!")
            data = response.json()
            print_info(f"  Status: {data.get('status', 'unknown')}")
            print_info(f"  Database: {data.get('database', 'unknown')}")
            print()
            print_success("✓ Deployment verified successfully!")
            return True
        else:
            print_warning(f"API returned status: {response.status_code}")
            print_warning("The API might need configuration")
            return False
    except Exception as e:
        print_error(f"API test failed: {e}")
        print_info("The files may be uploaded but not configured correctly")
        return False

def show_environment():
    """Show current environment configuration"""
    config = get_config()
    
    print(f"\n{Colors.BOLD}Current Environment: {config['env_name']}{Colors.ENDC}")
    print(f"Description: {config['description']}")
    print()
    print("FTP Paths:")
    print(f"  API: {config['ftp_api']}")
    print(f"  Website: {config['ftp_website']}")
    print()
    print("URLs:")
    print(f"  API: {config['api_url']}")
    print(f"  Website: {config['website_url']}")
    print(f"  Test Page: {config['test_page']}")
    print()

def main():
    print(f"{Colors.BOLD}")
    print("╔═══════════════════════════════════════════════════════════════════╗")
    print("║          Unified Deployment System v1.0                          ║")
    print("╚═══════════════════════════════════════════════════════════════════╝")
    print(f"{Colors.ENDC}")
    
    # Get configuration
    config = get_config()
    
    # Show environment
    show_environment()
    
    # Parse arguments
    if len(sys.argv) < 2:
        print_error("Please specify what to deploy")
        print()
        print("Usage:")
        print("  python3 deploy.py --backend     # Deploy API only")
        print("  python3 deploy.py --frontend    # Deploy website only")
        print("  python3 deploy.py --full        # Deploy everything")
        print("  python3 deploy.py --verify      # Test deployment")
        print()
        print("To switch environments:")
        print("  Edit env_config.py and change CURRENT_ENVIRONMENT")
        sys.exit(1)
    
    # Parse options
    backend = '--backend' in sys.argv or '--full' in sys.argv
    frontend = '--frontend' in sys.argv or '--full' in sys.argv
    verify = '--verify' in sys.argv
    full = '--full' in sys.argv
    
    # Confirmation for production
    if config['environment'] == 'production' and (backend or frontend):
        print_warning("⚠ WARNING: Deploying to PRODUCTION!")
        print_warning("This will deploy to the live website!")
        response = input("Are you sure? (yes/no): ")
        if response.lower() not in ['yes', 'y']:
            print_info("Deployment cancelled")
            sys.exit(0)
        print()
    
    # Deploy backend
    if backend:
        success = deploy_backend(config)
        if not success:
            print_error("Backend deployment failed!")
            sys.exit(1)
    
    # Deploy frontend
    if frontend:
        success = deploy_frontend(config)
        if not success:
            print_error("Frontend deployment failed!")
            sys.exit(1)
    
    # Verify
    if verify or full:
        time.sleep(2)
        verify_deployment(config)
    
    # Summary
    if backend or frontend:
        print_header("Deployment Complete!")
        print_success(f"Deployed to: {config['env_name']}")
        print()
        print_info("Access your deployment:")
        print(f"  Website: {config['website_url']}")
        print(f"  API: {config['api_url']}")
        print(f"  Test Page: {config['test_page']}")
        print()
        
        if config['environment'] == 'test':
            print_info("To deploy to production:")
            print("  1. Edit env_config.py")
            print("  2. Change CURRENT_ENVIRONMENT = 'production'")
            print("  3. Run: python3 deploy.py --full")
            print()

if __name__ == '__main__':
    main()
