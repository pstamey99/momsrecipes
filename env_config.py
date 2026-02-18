# Deployment Environments Configuration
# =============================================================================
# Switch between TEST and PRODUCTION environments

ENVIRONMENTS = {
    'test': {
        'name': 'TEST (using production path)',
        'description': 'Testing in production location - be careful!',
        
        # FTP Paths - Correct web root
        'ftp_base': '/public_html',
        'ftp_api': '/public_html/momsrecipes/api',
        'ftp_website': '/public_html/momsrecipes',
        
        # URLs - Production URLs
        'base_url': 'https://paulstamey.com',
        'api_url': 'https://paulstamey.com/momsrecipes/api/index.php',
        'website_url': 'https://paulstamey.com/momsrecipes',
        'test_page': 'https://paulstamey.com/momsrecipes/api/test.html',
    },
    
    'production': {
        'name': 'PRODUCTION',
        'description': 'Live production environment',
        
        # FTP Paths - Correct web root
        'ftp_base': '/public_html',
        'ftp_api': '/public_html/momsrecipes/api',
        'ftp_website': '/public_html/momsrecipes',
        
        # URLs
        'base_url': 'https://paulstamey.com',
        'api_url': 'https://paulstamey.com/momsrecipes/api/index.php',
        'website_url': 'https://paulstamey.com/momsrecipes',
        'test_page': 'https://paulstamey.com/momsrecipes/api/test.html',
    }
}

# =============================================================================
# CHANGE THIS TO SWITCH ENVIRONMENTS
# =============================================================================
CURRENT_ENVIRONMENT = 'test'  # Change to 'production' when ready
# =============================================================================

# FTP Connection (same for both)
FTP = {
    'host': 'ftp.paulstamey.com',
    'user': 'paul.stamey@paulstamey.com',  # Updated with domain
    'pass': 'Conradisnot64!!',  # UPDATE THIS
    'port': 21,
    'timeout': 30
}

# Get current environment config
def get_config():
    """Get the current environment configuration"""
    env = ENVIRONMENTS[CURRENT_ENVIRONMENT]
    return {
        'environment': CURRENT_ENVIRONMENT,
        'env_name': env['name'],
        'description': env['description'],
        
        # FTP paths
        'ftp_host': FTP['host'],
        'ftp_user': FTP['user'],
        'ftp_pass': FTP['pass'],
        'ftp_port': FTP['port'],
        'ftp_timeout': FTP['timeout'],
        'ftp_base': env['ftp_base'],
        'ftp_api': env['ftp_api'],
        'ftp_website': env['ftp_website'],
        
        # URLs
        'base_url': env['base_url'],
        'api_url': env['api_url'],
        'website_url': env['website_url'],
        'test_page': env['test_page'],
    }

if __name__ == '__main__':
    # Show current configuration
    config = get_config()
    print(f"\nCurrent Environment: {config['env_name']}")
    print(f"Description: {config['description']}")
    print(f"\nFTP Paths:")
    print(f"  API: {config['ftp_api']}")
    print(f"  Website: {config['ftp_website']}")
    print(f"\nURLs:")
    print(f"  API: {config['api_url']}")
    print(f"  Website: {config['website_url']}")
    print(f"  Test Page: {config['test_page']}")
    print(f"\nTo switch environments, edit env_config.py and change CURRENT_ENVIRONMENT\n")
