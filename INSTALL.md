# FoxDesk — Installation Guide

> Fresh installation instructions for shared hosting (FTP), VPS, or dedicated servers.
> Version: **0.3.107** | Requires: PHP 8.1+ | MySQL 5.7+ / MariaDB 10.2+

---

## Table of Contents

1. [Requirements](#requirements)
2. [Quick Start (5 Minutes)](#quick-start-5-minutes)
3. [Detailed Installation](#detailed-installation)
4. [Configuration Reference](#configuration-reference)
5. [Post-Installation Setup](#post-installation-setup)
6. [Shared Hosting (cPanel) Guide](#shared-hosting-cpanel-guide)
7. [VPS / Dedicated Server Guide](#vps--dedicated-server-guide)
8. [Cron Jobs Setup](#cron-jobs-setup)
9. [Email Configuration](#email-configuration)
10. [Security Hardening](#security-hardening)
11. [Troubleshooting](#troubleshooting)

---

## Requirements

### Server

| Requirement | Minimum | Recommended |
|------------|---------|-------------|
| PHP | 8.1 | 8.2+ |
| MySQL | 5.7 | 8.0+ |
| MariaDB | 10.2 | 10.6+ |
| Disk space | 50 MB | 200 MB+ (for uploads & backups) |

### PHP Extensions

| Extension | Required | Purpose |
|-----------|----------|---------|
| `pdo_mysql` | Yes | Database connection |
| `mbstring` | Yes | Multi-byte string handling (UTF-8) |
| `json` | Yes | API & data processing |
| `openssl` | Yes | HTTPS, token generation |
| `fileinfo` | Recommended | File upload MIME detection |
| `imap` | Optional | Email-to-ticket ingest |
| `zip` | Recommended | Backup & update system |

### Apache Modules

| Module | Required | Purpose |
|--------|----------|---------|
| `mod_rewrite` | Yes | Authorization header passthrough |

---

## Quick Start (5 Minutes)

1. **Upload** all files to your web root via FTP
2. **Create** a MySQL database and user
3. **Copy** `config.example.php` to `config.php`
4. **Edit** `config.php` with your database credentials and APP_URL
5. **Open** `https://your-domain.tld/install.php` in your browser
6. **Follow** the two-step installer (database setup + admin account)
7. **Delete** `install.php` from the server (security)
8. **Log in** at `https://your-domain.tld/`

---

## Detailed Installation

### Step 1: Prepare Files

Download or extract the distribution package. You should have this structure:

```
your-web-root/
  .htaccess
  index.php
  install.php
  config.example.php
  upgrade.php
  rescue.php
  version.json
  image.php
  theme.css
  tailwind.min.css
  assets/
  bin/
  includes/
  pages/
```

Upload ALL files to your web server document root. Maintain the directory structure exactly.

### Step 2: Create Database

Using phpMyAdmin, cPanel, or MySQL CLI:

```sql
CREATE DATABASE helpdesk_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'helpdesk_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON helpdesk_db.* TO 'helpdesk_user'@'localhost';
FLUSH PRIVILEGES;
```

### Step 3: Create Configuration

Copy `config.example.php` to `config.php`:

```bash
cp config.example.php config.php
```

Edit `config.php` with your settings:

```php
<?php
// Database
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'helpdesk_db');
define('DB_USER', 'helpdesk_user');
define('DB_PASS', 'strong_password_here');

// Security - generate a random 64-char hex string
// Use: php -r "echo bin2hex(random_bytes(32));"
define('SECRET_KEY', 'your_64_char_hex_string_here');

// Application
define('APP_NAME', 'My Helpdesk');
define('APP_URL', 'https://helpdesk.example.com');

// ... keep IMAP and upload settings from example
```

### Step 4: Set Directory Permissions

```bash
# Writable directories (created automatically on first use)
chmod 755 uploads/
chmod 755 backups/

# Config file (read-only after setup)
chmod 644 config.php

# Protect sensitive files
chmod 644 .htaccess
```

On shared hosting, directories usually default to 755 which is correct.

### Step 5: Run Web Installer

Open `https://your-domain.tld/install.php` in your browser.

**Step 1 of 2: Database Setup**
- Enter your database credentials (should match config.php)
- The installer tests the connection
- Creates all database tables from `includes/schema.sql`

**Step 2 of 2: Admin Account**
- Enter your app name
- Create the admin account (email, name, password)
- Sets up default statuses, priorities, and ticket types

### Step 6: Post-Install Security

**IMPORTANT: Delete or rename `install.php` after installation:**

```bash
rm install.php
# or
mv install.php install.php.bak
```

This prevents anyone from re-running the installer.

---

## Configuration Reference

### config.php - Full Reference

```php
<?php
// ============================================================
// DATABASE
// ============================================================
define('DB_HOST', 'localhost');          // Database server (usually 'localhost')
define('DB_PORT', '3306');              // MySQL port (default 3306)
define('DB_NAME', 'helpdesk_db');       // Database name
define('DB_USER', 'helpdesk_user');     // Database username
define('DB_PASS', 'password');          // Database password

// ============================================================
// SECURITY
// ============================================================
// Generate with: php -r "echo bin2hex(random_bytes(32));"
define('SECRET_KEY', '64_char_hex_string');

// ============================================================
// APPLICATION
// ============================================================
define('APP_NAME', 'FoxDesk');
define('APP_URL', 'https://your-domain.tld');  // No trailing slash!

// ============================================================
// EMAIL INGEST (IMAP) - Optional
// ============================================================
define('IMAP_ENABLED', false);                    // Enable email-to-ticket
define('IMAP_HOST', 'imap.example.com');          // IMAP server
define('IMAP_PORT', 993);                         // IMAP port
define('IMAP_ENCRYPTION', 'ssl');                 // ssl | tls | none
define('IMAP_VALIDATE_CERT', false);              // Validate SSL certificate
define('IMAP_USERNAME', 'support@example.com');   // IMAP login
define('IMAP_PASSWORD', 'email_password');         // IMAP password
define('IMAP_FOLDER', 'INBOX');                   // Folder to monitor
define('IMAP_PROCESSED_FOLDER', 'Processed');     // Move processed emails here
define('IMAP_FAILED_FOLDER', 'Failed');           // Move failed emails here
define('IMAP_MAX_EMAILS_PER_RUN', 50);            // Max emails per cron run
define('IMAP_MAX_ATTACHMENT_SIZE', 10 * 1024 * 1024);  // 10 MB
define('IMAP_DENY_EXTENSIONS', 'php,phtml,php3,php4,php5,phar,exe,bat,cmd,js,vbs,ps1,sh');
define('IMAP_STORAGE_BASE', 'storage/tickets');
define('IMAP_MARK_SEEN_ON_SKIP', true);
define('IMAP_ALLOW_UNKNOWN_SENDERS', false);      // Only whitelisted senders

// ============================================================
// UPLOADS
// ============================================================
define('UPLOAD_DIR', 'uploads/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);      // 10 MB

// ============================================================
// TIMEZONE
// ============================================================
date_default_timezone_set('Europe/Prague');        // Your timezone
```

---

## Post-Installation Setup

After the installer completes, log in as admin and configure:

### 1. General Settings (Admin > Settings > General)

- **App Name** — Display name for your helpdesk
- **Logo** — Upload a custom logo
- **Favicon** — Upload a custom favicon
- **Primary Color** — Brand color for UI accents
- **Language** — Default language (en, cs, de, es, it)
- **Currency** — For billing (CZK, EUR, USD, etc.)
- **Time Rounding** — Round time to nearest N minutes (0, 15, 30, 60)

### 2. Email (Admin > Settings > Email)

Configure SMTP for outbound email notifications:
- SMTP Host, Port, Encryption (TLS/SSL)
- Username, Password
- From Email, From Name
- Use "Test SMTP" button to verify

### 3. Ticket Workflow

- **Statuses** (Admin > Statuses) — Customize workflow states, drag to reorder
- **Priorities** (Admin > Priorities) — Set severity levels, drag to reorder
- **Ticket Types** (Admin > Ticket Types) — Issue categories

### 4. Organizations & Users

- Create organizations (Admin > Organizations) with billing rates
- Add agents/staff (Admin > Users)
- Create client accounts (Admin > Clients)

### 5. AI Agents (Optional)

To set up AI agent integration:
1. Go to Admin > Users > AI Agents tab
2. Create a new agent (name, cost rate)
3. Generate an API token
4. Visit Agent Connect for system prompt and API docs
5. Use the token in your AI automation

### 6. Recurring Tasks (Optional)

- Go to Admin > Recurring Tasks
- Create templates for tickets that should be created on schedule
- Set recurrence: weekly, monthly, yearly, or custom interval

### 7. Notifications

Notification preferences are per-user in Profile settings:
- Email notifications on/off
- In-app notifications on/off
- Sound notifications on/off

### 8. Security

- Open **Admin > Settings > Security** and decide which roles must use 2FA
- Ask each admin/agent to enable TOTP in their **Profile** and store backup codes safely
- Review **Allowed Senders** if you plan to use IMAP email-to-ticket

---

## Shared Hosting (cPanel) Guide

### File Upload via cPanel File Manager

1. Log into cPanel
2. Open **File Manager**
3. Navigate to `public_html/` (or your subdomain directory)
4. Upload all files (or upload as ZIP and extract)
5. Verify `.htaccess` is present (may be hidden — enable "Show Hidden Files")

### Database via cPanel

1. Open **MySQL Databases**
2. Create a new database
3. Create a new user with a strong password
4. Add user to database with "ALL PRIVILEGES"
5. Note the full database name (usually `cpaneluser_dbname`)

### PHP Version

1. Open **Select PHP Version** or **MultiPHP Manager**
2. Set PHP to 8.1 or higher
3. Ensure `pdo_mysql`, `mbstring`, `json`, `openssl` extensions are enabled

### Pseudo-Cron (No Cron Access Needed)

FoxDesk includes a pseudo-cron system that automatically runs background tasks (recurring tasks, email ingest) on each page load. This works out of the box on shared hosting without configuring cron jobs.

To enable: Admin > Settings > enable Pseudo-Cron.

### Cron Jobs via cPanel (Optional, Recommended)

If your hosting supports cron jobs, set them up for more reliable scheduling:

1. Open **Cron Jobs**
2. Add jobs (see [Cron Jobs Setup](#cron-jobs-setup))

---

## VPS / Dedicated Server Guide

### Nginx Configuration

If using Nginx instead of Apache, add this to your server block:

```nginx
server {
    listen 443 ssl;
    server_name helpdesk.example.com;
    root /var/www/helpdesk;
    index index.php;

    # Pass Authorization header
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        # Pass Authorization header to PHP
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    }

    # Protect sensitive directories
    location ~ ^/(backups|bin|includes)/ {
        deny all;
    }

    # Prevent PHP execution in uploads
    location ~ ^/uploads/.*\.php$ {
        deny all;
    }
}
```

### Apache Virtual Host

```apache
<VirtualHost *:443>
    ServerName helpdesk.example.com
    DocumentRoot /var/www/helpdesk

    <Directory /var/www/helpdesk>
        AllowOverride All
        Require all granted
    </Directory>

    # Protect directories
    <Directory /var/www/helpdesk/backups>
        Require all denied
    </Directory>
    <Directory /var/www/helpdesk/bin>
        Require all denied
    </Directory>
</VirtualHost>
```

---

## Cron Jobs Setup

### Recommended Cron Jobs

```bash
# Email ingest (every 5 minutes) - only if IMAP is enabled
*/5 * * * * /usr/bin/php /var/www/helpdesk/bin/ingest-emails.php >> /var/log/helpdesk-email.log 2>&1

# Recurring tasks (every hour)
0 * * * * /usr/bin/php /var/www/helpdesk/bin/process-recurring-tasks.php >> /var/log/helpdesk-recurring.log 2>&1

# Maintenance cleanup (daily at 3 AM)
0 3 * * * /usr/bin/php /var/www/helpdesk/bin/run-maintenance.php >> /var/log/helpdesk-maintenance.log 2>&1
```

### cPanel Cron Format

In cPanel Cron Jobs, use the full PHP path:
```
/usr/local/bin/php /home/username/public_html/bin/process-recurring-tasks.php
```

Find your PHP path with: `which php` or ask your hosting provider.

### Pseudo-Cron Alternative

If you cannot set up system cron jobs, enable **Pseudo-Cron** in Admin > Settings. FoxDesk will automatically run background tasks (email ingest, recurring tasks) on each page load by an authenticated user.

---

## Email Configuration

### SMTP Setup (Outbound)

Configure in **Admin > Settings > Email**:

**Gmail/Google Workspace:**
- Host: `smtp.gmail.com`
- Port: `587`
- Encryption: `TLS`
- Username: Your Gmail address
- Password: App-specific password (not your regular password)

**Microsoft 365:**
- Host: `smtp.office365.com`
- Port: `587`
- Encryption: `TLS`
- Username: Your email address
- Password: Your password or app password

**Generic SMTP:**
- Host: Your SMTP server
- Port: 587 (TLS) or 465 (SSL) or 25 (none)
- Encryption: Match your port

### IMAP Setup (Inbound - Email to Ticket)

Configure in `config.php`:

1. Set `IMAP_ENABLED` to `true`
2. Enter IMAP server credentials
3. Create the IMAP folders (Processed, Failed)
4. Set up the cron job for `bin/ingest-emails.php` (or enable pseudo-cron)
5. Configure allowed senders in Admin > Settings (Allowed Senders section)

---

## Security Hardening

### Essential Steps

1. **Delete `install.php`** after installation
2. **Use HTTPS** — Get a free SSL certificate from Let's Encrypt
3. **Strong SECRET_KEY** — Generate 64 random hex chars
4. **Strong passwords** — Enforce for all admin/agent accounts
5. **Enable 2FA for sensitive roles** — Configure requirements in Admin > Settings > Security
6. **Restrict file permissions** — `config.php` should be 644 or 640

### File Protection

The `.htaccess` file included with FoxDesk:
- Passes Authorization headers to PHP (required for API)
- Blocks direct access to `backups/` directory
- Prevents PHP execution in `uploads/` directory

### Additional Server Hardening

```apache
# Block access to sensitive files (add to root .htaccess)
<FilesMatch "(^\.ht|config\.php|schema\.sql|composer\.json)">
    Require all denied
</FilesMatch>

# Block directory listing
Options -Indexes
```

### PHP Configuration (php.ini)

```ini
; Recommended settings
expose_php = Off
display_errors = Off
log_errors = On
error_log = /path/to/php-error.log
upload_max_filesize = 10M
post_max_size = 12M
max_execution_time = 60
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Lax
```

---

## Troubleshooting

### Blank Page / HTTP 500

1. Check PHP error log on server
2. Verify `config.php` exists and has correct DB credentials
3. Verify PHP version >= 8.1
4. Check that `pdo_mysql` extension is enabled
5. Verify database exists and user has permissions

### "Access Denied" on Database

- Verify DB_HOST (use `localhost` for shared hosting, `127.0.0.1` for some VPS)
- Check username and password
- Ensure user has privileges on the specific database

### CSS Not Loading / Broken Layout

- Verify `theme.css` and `tailwind.min.css` were uploaded
- Check that the files are in the web root (same directory as `index.php`)
- Clear browser cache (Ctrl+Shift+R)

### Authorization Header Not Passing (API 401)

- Verify `.htaccess` was uploaded (it may be hidden)
- Check that `mod_rewrite` is enabled
- For Nginx: add `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`

### File Upload Errors

- Check `upload_max_filesize` and `post_max_size` in php.ini
- Verify `uploads/` directory exists and is writable (755)
- Check `MAX_UPLOAD_SIZE` in config.php

### Cron Jobs Not Running

- Verify the PHP path is correct
- Check the script file path is absolute
- Look at cron logs: `grep CRON /var/log/syslog`
- Test manually: `php /path/to/bin/process-recurring-tasks.php`
- Alternative: Enable pseudo-cron in Admin > Settings

### Update Fails

- Use `rescue.php` to disable maintenance mode if stuck
- Check that `backups/` directory is writable
- Try manual update: download ZIP from foxdesk.org, upload via Admin > Updates

### "Headers already sent" Error

- Check for whitespace or BOM characters before `<?php` in config.php
- Ensure no file has output before `session_start()` is called
