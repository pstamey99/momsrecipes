<?php
/**
 * config.php ADDITIONS — add these constants to your existing src/api/config.php
 * (Find the file and paste these lines at the bottom, before the closing ?>)
 */

// ── Email / Notifications ─────────────────────────────────────────────────────

// SMTP settings — Namecheap mail server
define('SMTP_HOST',     'mail.paulstamey.com');    // Your Namecheap mail server
define('SMTP_USERNAME', 'noreply@paulstamey.com'); // Email account to send FROM
define('SMTP_PASSWORD', 'YOUR_EMAIL_PASSWORD');    // ← fill this in
define('SMTP_PORT',     465);
define('SMTP_SECURE',   'ssl');                    // 'ssl' for port 465
define('FROM_EMAIL',    'noreply@paulstamey.com');
define('FROM_NAME',     "Mom's Recipes");
define('SITE_URL',      'https://paulstamey.com/momsrecipes');

// Cron secret — change this to any random string, use same value in cron job URL
define('DIGEST_CRON_SECRET', 'change-me-to-something-random-123');
