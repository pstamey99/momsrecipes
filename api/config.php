<?php
/**
 * Configuration File
 * Database and application settings
 */

// ── Email / Notifications ─────────────────────────────────────────────────────

// SMTP settings — Namecheap mail server
define('SMTP_HOST',     'mail.paulstamey.com');    // Your Namecheap mail server
define('SMTP_USERNAME', 'noreply@paulstamey.com'); // Email account to send FROM
define('SMTP_PASSWORD', 'Grahamisnot64!!');    // ← fill this in
define('SMTP_PORT',     465);
define('SMTP_SECURE',   'ssl');                    // 'ssl' for port 465
define('FROM_EMAIL',    'noreply@paulstamey.com');
define('FROM_NAME',     "Mom's Recipes");
define('SITE_URL',      'https://paulstamey.com/momsrecipes');

// Cron secret — change this to any random string, use same value in cron job URL
define('DIGEST_CRON_SECRET', 'mr7Kx9#pQ2$wN4vL');

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
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_USERNAME', 'paul.stamey@stameyconsulting.com');
define('SMTP_PASSWORD', 'cpxeuyipdpxbtwyv'); // App Password, no spaces
define('SMTP_PORT',     587);
define('SMTP_SECURE',   'tls');
define('FROM_EMAIL',    'paul.stamey@stameyconsulting.com');
define('FROM_NAME',     "Mom's Recipes");
define('SITE_URL',      'https://paulstamey.com/momsrecipes');
define('DIGEST_CRON_SECRET', 'mr7Kx9#pQ2$wN4vL');