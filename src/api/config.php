<?php
/**
 * Configuration File
 * Database and application settings
 */

// Database configuration
define('DB_PATH', __DIR__ . '/data/recipes.db');
define('DB_BACKUP_DIR', __DIR__ . '/data/backups');

// Ensure data directory exists
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0755, true);
}

if (!is_dir(DB_BACKUP_DIR)) {
    mkdir(DB_BACKUP_DIR, 0755, true);
}

// Application settings
define('API_VERSION', '1.0');
define('MAX_RECIPE_TITLE_LENGTH', 200);
define('MAX_IMAGE_SIZE', 5242880); // 5MB in bytes

// Timezone
date_default_timezone_set('America/Los_Angeles');

// Authentication settings (optional - can be extended later)
define('AUTH_ENABLED', false);
define('API_KEY', 'your-secret-api-key-here'); // Change this!

// Rate limiting (optional)
define('RATE_LIMIT_ENABLED', false);
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 3600); // 1 hour in seconds

// Anthropic API settings (optional - can be extended later)
define('ANTHROPIC_API_KEY', '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');

// Logging
define('LOG_FILE', __DIR__ . '/logs/api.log');
define('LOG_ENABLED', true);

// Ensure log directory exists
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Backup settings
define('AUTO_BACKUP_ENABLED', true);
define('BACKUP_RETENTION_DAYS', 30);

// Environment detection
define('IS_PRODUCTION', !preg_match('/localhost|127\.0\.0\.1/', $_SERVER['HTTP_HOST'] ?? ''));

// Error reporting based on environment
if (IS_PRODUCTION) {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Email / notification constants (required by mailer.php and index.php)
if (!defined('FROM_EMAIL'))        define('FROM_EMAIL',        'noreply@paulstamey.com');
if (!defined('FROM_NAME'))         define('FROM_NAME',         "Mom's Recipes");
if (!defined('SITE_URL'))          define('SITE_URL',          'https://paulstamey.com/momsrecipes');
if (!defined('DIGEST_CRON_SECRET'))define('DIGEST_CRON_SECRET','mr7Kx9#pQ2$wN4vL');
if (!defined('SMTP_HOST'))     define('SMTP_HOST',     'smtp.gmail.com');
if (!defined('SMTP_USERNAME')) define('SMTP_USERNAME', 'paul.stamey@stameyconsulting.com');
if (!defined('SMTP_PASSWORD')) define('SMTP_PASSWORD', 'ywuomlgzyrfovvqk');
if (!defined('SMTP_PORT'))     define('SMTP_PORT',     587);
if (!defined('SMTP_SECURE'))   define('SMTP_SECURE',   'tls');
