# Email Notifications — Deployment Guide
## Mom's Recipes · paulstamey.com/momsrecipes

---

## Files in This Package

| File | Purpose |
|------|---------|
| `mailer.php` | Complete email helper — upload to `src/api/` |
| `database_patch.php` | Code snippets to paste into `src/api/database.php` |
| `index_patch.php` | Code snippets to paste into `src/api/index.php` |
| `script_patch.js` | Code snippets to paste into `src/frontend/script.js` and `index.html` |

---

## Step 1: Download PHPMailer (one-time setup)

PHPMailer is a free library — download only 3 files.

```
https://github.com/PHPMailer/PHPMailer/releases/latest
```

From the zip, you only need these 3 files from the `src/` folder:
- `PHPMailer.php`
- `SMTP.php`
- `Exception.php`

Create this folder in your project:
```
src/api/vendor/phpmailer/src/
```

Place the 3 files there. Your structure should look like:
```
src/api/
├── vendor/
│   └── phpmailer/
│       └── src/
│           ├── PHPMailer.php
│           ├── SMTP.php
│           └── Exception.php
├── mailer.php          ← new file from this package
├── database.php        ← patch this
├── index.php           ← patch this
└── ...
```

---

## Step 2: Configure mailer.php

Open `mailer.php` and fill in your SMTP credentials near the top:

```php
define('SMTP_HOST',     'mail.paulstamey.com');   // Namecheap mail server
define('SMTP_USERNAME', 'noreply@paulstamey.com'); // Your email address
define('SMTP_PASSWORD', 'YOUR_EMAIL_PASSWORD');    // Email account password
define('SMTP_PORT',     465);                      // 465 for SSL
define('SMTP_SECURE',   PHPMailer::ENCRYPTION_SMTPS);
define('FROM_EMAIL',    'noreply@paulstamey.com');
define('FROM_NAME',     "Mom's Recipes");
define('SITE_URL',      'https://paulstamey.com/momsrecipes');
```

**Finding your Namecheap SMTP settings:**
1. Log into Namecheap cPanel
2. Go to Email Accounts → Connect Devices
3. Your mail server is usually `mail.yourdomain.com`
4. Use the email account you want to send FROM (create one like `noreply@paulstamey.com` if you haven't)

---

## Step 3: Patch database.php

Open `src/api/database.php`. Find the `migrateSchema()` method.

**Paste Target 1** — Add at the END of `migrateSchema()`, before its closing `}`:

```php
// Add email column to users table if missing (for existing DBs)
try {
    $this->db->exec("ALTER TABLE users ADD COLUMN email TEXT");
} catch (Exception $e) { /* already exists */ }

// Add display_name column to users table if missing
try {
    $this->db->exec("ALTER TABLE users ADD COLUMN display_name TEXT");
} catch (Exception $e) { /* already exists */ }

// Create user_preferences table
$this->db->exec("CREATE TABLE IF NOT EXISTS user_preferences (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT NOT NULL UNIQUE,
    email       TEXT,
    notify_new_recipe   INTEGER NOT NULL DEFAULT 1,
    notify_reactions    INTEGER NOT NULL DEFAULT 0,
    notify_edits        INTEGER NOT NULL DEFAULT 0,
    notify_weekly       INTEGER NOT NULL DEFAULT 0,
    notifications_enabled INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
)");
```

**Paste Target 2** — Add these new methods to the Database class body (after the last existing method):

Copy the methods from the `/* ... */` block in `database_patch.php`:
- `getUserPreferences()`
- `saveUserPreferences()`
- `getNotificationSubscribers()`
- `getWeeklyDigestData()`

---

## Step 4: Patch index.php

Open `src/api/index.php`.

**4a.** Near the top, after `require_once 'database.php';`, add:
```php
require_once __DIR__ . '/mailer.php';
```

**4b.** In the main action routing block, add these new cases from `index_patch.php`:
- `get_preferences`
- `save_preferences`
- `send_test_email`
- `send_weekly_digest`

**4c.** Hook notifications into existing actions (also in `index_patch.php`):
- After `create_recipe` succeeds → call `notifyNewRecipe()`
- After `save_reaction` succeeds → call `notifyReaction()`
- After `update_recipe` succeeds → call `notifyRecipeEdited()`

---

## Step 5: Patch script.js and index.html

**5a.** In `src/frontend/script.js`, add these functions from `script_patch.js`:
- `loadNotificationPreferences()`
- `saveNotificationPreferences()`
- `sendTestEmail()`
- `updateNotifTogglesVisibility()`
- `isValidEmail()`

**5b.** In `src/frontend/index.html`, paste the notification preferences HTML block
(from the `/* HTML block */` comment in `script_patch.js`) into your profile modal,
after the existing profile fields.

**5c.** Where your profile panel opens (search for where the profile modal/drawer is shown),
add the call to load preferences:
```javascript
loadNotificationPreferences(currentUser);

// Show test email button for paul only
const testBtn = document.getElementById('btn-send-test-email');
if (testBtn && (currentUser === 'paul' || currentUser === 'pstamey')) {
    testBtn.style.display = 'inline-block';
}
```

---

## Step 6: Deploy

```bash
# API changes (database + new actions + mailer)
python3 recipe_pipeline.py --deploy-api

# Frontend changes (profile UI)
python3 recipe_pipeline.py --build
python3 recipe_pipeline.py --deploy-frontend
```

The `user_preferences` table will be created automatically on first API call
(the migration runs on every startup).

---

## Step 7: Test

1. Log in as paul
2. Open your profile/account area
3. Enter your email address
4. Toggle "Enable email notifications" ON
5. Check "New recipe added" and "Weekly digest"
6. Click **Save preferences**
7. Click **Send test email** — check your inbox

---

## Step 8: Weekly Digest Cron Job (optional)

To send the digest automatically every Sunday at 9am:

In Namecheap cPanel → Cron Jobs, add:
```
0 9 * * 0   curl -s "https://paulstamey.com/momsrecipes/api/index.php?action=send_weekly_digest&secret=YOUR_CRON_SECRET_HERE" > /dev/null 2>&1
```

Replace `YOUR_CRON_SECRET_HERE` with a random string of your choice — also update it in `index_patch.php` before deploying.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Test email not arriving | Check spam folder; verify SMTP credentials in mailer.php |
| "Failed to load PHPMailer" error | Confirm the 3 PHPMailer files are in `src/api/vendor/phpmailer/src/` |
| Preferences not saving | Check browser console for API error; verify `user_preferences` table exists |
| SMTP auth error on Namecheap | Use port 465 with SMTPS (not 587/TLS) for Namecheap mail |
| Emails going to spam | Use a real domain email (not Gmail) as FROM address; Namecheap email works great here |

---

## Database Schema Added

```sql
-- New table
CREATE TABLE user_preferences (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    username              TEXT NOT NULL UNIQUE,
    email                 TEXT,
    notify_new_recipe     INTEGER NOT NULL DEFAULT 1,
    notify_reactions      INTEGER NOT NULL DEFAULT 0,
    notify_edits          INTEGER NOT NULL DEFAULT 0,
    notify_weekly         INTEGER NOT NULL DEFAULT 0,
    notifications_enabled INTEGER NOT NULL DEFAULT 1,
    created_at            TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at            TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Column added to existing users table
ALTER TABLE users ADD COLUMN email TEXT;
ALTER TABLE users ADD COLUMN display_name TEXT;
```

---

## API Endpoints Added

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `get_preferences` | GET | Any user | Load preferences for a username |
| `save_preferences` | POST | Any user | Save preferences |
| `send_test_email` | POST | Paul only | Send test email to saved address |
| `send_weekly_digest` | POST | Cron secret | Trigger weekly digest |
