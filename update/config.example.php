<?php
/**
 * FoxDesk - Configuration
 * Sample configuration template
 */

// Shared hosting/FTP usually uses localhost here.
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

define('SECRET_KEY', 'generate_64_hex_secret_here');

define('APP_NAME', 'FoxDesk');
define('APP_URL', 'https://your-domain.tld');
// define('APP_DEBUG', false); // Set true to enable debug mode
define('APP_VERSION', '0.3.46');

// Incoming email ingest (IMAP)
define('IMAP_ENABLED', false);
define('IMAP_HOST', '');
define('IMAP_PORT', 993);
define('IMAP_ENCRYPTION', 'ssl'); // ssl|tls|none
define('IMAP_VALIDATE_CERT', false);
define('IMAP_USERNAME', '');
define('IMAP_PASSWORD', '');
define('IMAP_FOLDER', 'INBOX');
define('IMAP_PROCESSED_FOLDER', 'Processed');
define('IMAP_FAILED_FOLDER', 'Failed');
define('IMAP_MAX_EMAILS_PER_RUN', 50);
define('IMAP_MAX_ATTACHMENT_SIZE', 10 * 1024 * 1024);
define('IMAP_DENY_EXTENSIONS', 'php,phtml,php3,php4,php5,phar,exe,bat,cmd,js,vbs,ps1,sh');
define('IMAP_STORAGE_BASE', 'storage/tickets');
define('IMAP_MARK_SEEN_ON_SKIP', true);
define('IMAP_ALLOW_UNKNOWN_SENDERS', false);

define('UPLOAD_DIR', 'uploads/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);

date_default_timezone_set('Europe/Prague');

