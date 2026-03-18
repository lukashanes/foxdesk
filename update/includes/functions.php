<?php
/**
 * Helper Functions
 */

// Load specialized function files
require_once BASE_PATH . '/includes/security-helpers.php';
require_once BASE_PATH . '/includes/settings-functions.php';
require_once BASE_PATH . '/includes/user-functions.php';
require_once BASE_PATH . '/includes/ticket-functions.php';
require_once BASE_PATH . '/includes/email-functions.php';
require_once BASE_PATH . '/includes/report-functions.php';
require_once BASE_PATH . '/includes/recurring-task-functions.php';
require_once BASE_PATH . '/includes/notification-functions.php';
require_once BASE_PATH . '/includes/icons.php';
require_once BASE_PATH . '/includes/components/date-input.php';

/**
 * Escape HTML output
 */
function e($string)
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Render safe HTML content (from rich text editor)
 * Allows basic formatting tags while preventing XSS
 */
function safe_html($html)
{
    if (empty($html)) return '';

    // Allow only safe HTML tags
    $allowed_tags = '<p><br><strong><b><em><i><u><s><strike><h1><h2><h3><h4><h5><h6><ul><ol><li><a><blockquote><pre><code><span><div>';
    $clean = strip_tags($html, $allowed_tags);

    // Clean up attributes - only allow href on links (with safe protocols) and class
    $clean = preg_replace_callback('/<a\s+([^>]*)>/i', function($matches) {
        $attrs = $matches[1];
        // Extract href if present
        if (preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $attrs, $hrefMatch)) {
            $href = $hrefMatch[1];
            // Only allow safe protocols
            if (preg_match('/^(https?:|mailto:|\/)/i', $href)) {
                return '<a href="' . e($href) . '" target="_blank" rel="noopener noreferrer">';
            }
        }
        return '<a>';
    }, $clean);

    // Remove all other attributes except class on certain elements
    $clean = preg_replace('/<(p|div|span|ul|ol|li|h[1-6]|blockquote|pre|code)\s+[^>]*>/i', '<$1>', $clean);

    // Remove any script/style/event handler content that might have slipped through
    $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $clean);
    $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $clean);
    $clean = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean);
    $clean = preg_replace('/\s*javascript\s*:/i', '', $clean);

    return $clean;
}

/**
 * Convert plain-text URLs in an HTML string into clickable <a> tags.
 * Must be called AFTER e() or safe_html() — operates on safe HTML output.
 * Skips URLs already inside <a> tags to avoid double-linking.
 */
function linkify_urls(string $html): string
{
    if ($html === '') return '';

    // Split HTML into segments: inside <a>...</a> vs. everything else
    // Odd-indexed parts are captured <a>...</a> blocks — pass through unchanged
    $parts = preg_split('/(<a\s[^>]*>.*?<\/a>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

    $result = '';
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            $result .= $part;
            continue;
        }

        // Linkify URLs in text outside <a> tags
        $result .= preg_replace_callback(
            '~
                (?:https?://|www\.)           # Must start with http://, https://, or www.
                [^\s<>\'"()\[\]]*             # URL body: no whitespace, HTML, quotes, brackets
                [^\s<>\'"()\[\].,;:!?\-]      # Must end with a non-punctuation char
            ~xi',
            function ($m) {
                $url = $m[0];
                $href = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
                return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
            },
            $part
        );
    }

    return $result;
}

/**
 * Check if content appears to be HTML (from rich text editor)
 */
function is_html_content($content)
{
    if (empty($content)) return false;
    return preg_match('/<[^>]+>/', $content) === 1;
}

/**
 * Render content as HTML or plain text based on its format
 */
function render_content($content)
{
    if (empty($content)) return '';

    if (is_html_content($content)) {
        return linkify_urls(safe_html($content));
    }

    // Plain text - escape, convert newlines, then linkify
    return linkify_urls(nl2br(e($content)));
}

/**
 * Convert an upload path (e.g. "uploads/file.png") to a URL served via image.php proxy.
 * Falls through for data: URIs, absolute URLs and empty values.
 */
function upload_url(string $path): string
{
    if ($path === '' || str_starts_with($path, 'data:') || str_starts_with($path, 'http')) {
        return $path;
    }
    // Strip any query-string suffix (?v=...) before building the URL
    $clean = explode('?', $path)[0];
    $filename = basename($clean);
    // Re-append cache-buster as a proper query param
    $qs = '';
    if (str_contains($path, '?')) {
        $qs = '&' . substr($path, strpos($path, '?') + 1);
    }
    return 'image.php?f=' . urlencode($filename) . $qs;
}

/**
 * Generate URL
 */
function url($page, $params = [])
{
    $url = 'index.php?page=' . $page;
    foreach ($params as $key => $value) {
        $url .= '&' . urlencode($key) . '=' . urlencode($value);
    }
    return $url;
}

/**
 * Generate secure ticket URL using hash
 */
function ticket_url($ticket, $params = [])
{
    // Accept ticket array or ID
    if (is_array($ticket)) {
        $hash = $ticket['hash'] ?? null;
        $id = $ticket['id'] ?? null;
    } else {
        $id = (int)$ticket;
        $hash = null;
        // Try to get hash from database
        if (function_exists('ticket_hash_column_exists') && ticket_hash_column_exists()) {
            $t = db_fetch_one("SELECT hash FROM tickets WHERE id = ?", [$id]);
            $hash = $t['hash'] ?? null;
        }
    }

    // Use hash if available, otherwise fall back to ID
    if (!empty($hash)) {
        return url('ticket', array_merge(['t' => $hash], $params));
    }

    // Fallback to ID (for backwards compatibility)
    return url('ticket', array_merge(['id' => $id], $params));
}

/**
 * Get active app language (default: English)
 */
function get_app_language()
{
    $allowed = ['en', 'cs', 'de', 'it', 'es'];
    $normalize = static function ($value) use ($allowed) {
        $value = strtolower(trim((string) $value));
        return in_array($value, $allowed, true) ? $value : null;
    };

    $requested = isset($_GET['lang']) ? $normalize($_GET['lang']) : null;
    if ($requested !== null) {
        $_SESSION['lang'] = $requested;
        $_SESSION['lang_override'] = true;

        if (function_exists('is_logged_in') && function_exists('current_user') && is_logged_in()) {
            $session_user = current_user();
            if (!empty($session_user['id'])) {
                $current_user_lang = $normalize($session_user['language'] ?? null);
                if ($current_user_lang !== $requested) {
                    try {
                        db_update('users', ['language' => $requested], 'id = ?', [(int) $session_user['id']]);
                        current_user(true);
                    } catch (Throwable $e) {
                        // Non-fatal: UI language still follows current request/session.
                    }
                }
            }
        }

        return $requested;
    }

    if (function_exists('is_logged_in') && function_exists('current_user') && is_logged_in()) {
        $session_user = current_user();
        $user_lang = $normalize($session_user['language'] ?? null);
        if ($user_lang !== null) {
            $_SESSION['lang'] = $user_lang;
            unset($_SESSION['lang_override']);
            return $user_lang;
        }
    }

    $session_lang = $normalize($_SESSION['lang'] ?? null);
    if ($session_lang !== null) {
        return $session_lang;
    }

    $setting_lang = $normalize(get_setting('app_language', 'en'));
    return $setting_lang ?? 'en';
}

/**
 * Translate UI strings with optional replacements.
 * Usage: t('Sign in') or t('Welcome, {name}!', ['name' => $user])
 */
function t($key, $replacements = [])
{
    static $translations = null;
    if ($translations === null) {
        $translations = require BASE_PATH . '/includes/translations.php';
    }
    $lang = get_app_language();
    $text = $translations[$lang][$key] ?? $translations['en'][$key] ?? $key;
    foreach ($replacements as $name => $value) {
        $text = str_replace('{' . $name . '}', $value, $text);
    }
    return $text;
}

/**
 * Get base URL for the application
 */
function get_base_url()
{
    if (defined('APP_URL') && !empty(APP_URL)) {
        return rtrim(APP_URL, '/');
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    if ($path === '.' || $path === '') {
        return $protocol . '://' . $host;
    }

    return $protocol . '://' . $host . $path;
}

/**
 * Redirect
 */
function redirect($page, $params = [])
{
    header('Location: ' . url($page, $params));
    exit;
}

/**
 * Flash message
 */
function flash($message, $type = 'success')
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

/**
 * Get and clear flash message
 */
function get_flash()
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// =============================================================================
// ORGANIZATIONS
// =============================================================================

/**
 * Get all organizations
 */
function get_organizations($include_inactive = false)
{
    $sql = "SELECT * FROM organizations";
    if (!$include_inactive) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY name";
    return db_fetch_all($sql);
}

/**
 * Get organization by ID
 */
function get_organization($id)
{
    return db_fetch_one("SELECT * FROM organizations WHERE id = ?", [$id]);
}


// =============================================================================
// PRIORITIES
// =============================================================================

/**
 * Get all priorities
 */
function get_priorities()
{
    try {
        return db_fetch_all("SELECT * FROM priorities ORDER BY sort_order");
    } catch (Exception $e) {
        // Fallback for old installations
        return [
            ['id' => 1, 'name' => t('Low'), 'slug' => 'low', 'color' => '#10b981'],
            ['id' => 2, 'name' => t('Medium'), 'slug' => 'medium', 'color' => '#3b82f6'],
            ['id' => 3, 'name' => t('High'), 'slug' => 'high', 'color' => '#f59e0b'],
            ['id' => 4, 'name' => t('Urgent'), 'slug' => 'urgent', 'color' => '#ef4444']
        ];
    }
}

/**
 * Get priority by ID
 */
function get_priority($id)
{
    return db_fetch_one("SELECT * FROM priorities WHERE id = ?", [$id]);
}

/**
 * Get default priority
 */
function get_default_priority()
{
    return db_fetch_one("SELECT * FROM priorities WHERE is_default = 1 LIMIT 1");
}

/**
 * Get priority label (legacy support)
 */
function get_priority_label($priority)
{
    // Check if it's an ID
    if (is_numeric($priority)) {
        $p = get_priority($priority);
        return $p ? $p['name'] : t('Medium');
    }

    // Legacy slug mapping
    $priorities = [
        'low' => t('Low'),
        'medium' => t('Medium'),
        'high' => t('High'),
        'urgent' => t('Urgent')
    ];
    return $priorities[$priority] ?? $priority;
}

/**
 * Get priority color (legacy support)
 */
function get_priority_color($priority)
{
    // Check if it's an ID
    if (is_numeric($priority)) {
        $p = get_priority($priority);
        return $p ? $p['color'] : '#3b82f6';
    }

    // Legacy slug mapping
    $colors = [
        'low' => '#10b981',
        'medium' => '#3b82f6',
        'high' => '#f59e0b',
        'urgent' => '#ef4444'
    ];
    return $colors[$priority] ?? '#6b7280';
}

// =============================================================================
// TICKET TYPES
// =============================================================================

/**
 * Get all ticket types
 */
function get_ticket_types($include_inactive = false)
{
    try {
        $sql = "SELECT * FROM ticket_types";
        if (!$include_inactive) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order";
        return db_fetch_all($sql);
    } catch (Exception $e) {
        // Fallback for old installations
        return [
            ['id' => 1, 'name' => t('General'), 'slug' => 'general', 'icon' => 'fa-file-alt', 'color' => '#3b82f6', 'is_default' => 1],
            ['id' => 2, 'name' => t('Quote request'), 'slug' => 'quote', 'icon' => 'fa-coins', 'color' => '#f59e0b', 'is_default' => 0],
            ['id' => 3, 'name' => t('Inquiry'), 'slug' => 'inquiry', 'icon' => 'fa-question-circle', 'color' => '#8b5cf6', 'is_default' => 0],
            ['id' => 4, 'name' => t('Bug report'), 'slug' => 'bug', 'icon' => 'fa-bug', 'color' => '#ef4444', 'is_default' => 0]
        ];
    }
}

/**
 * Get ticket type by slug
 */
function get_ticket_type($slug)
{
    try {
        return db_fetch_one("SELECT * FROM ticket_types WHERE slug = ?", [$slug]);
    } catch (Exception $e) {
        return null;
    }
}

// =============================================================================
// STATUSES
// =============================================================================

/**
 * Get all statuses
 */
function get_statuses()
{
    return db_fetch_all("SELECT * FROM statuses ORDER BY sort_order");
}

/**
 * Get status by ID
 */
function get_status($id)
{
    return db_fetch_one("SELECT * FROM statuses WHERE id = ?", [$id]);
}

/**
 * Get default status
 */
function get_default_status()
{
    return db_fetch_one("SELECT * FROM statuses WHERE is_default = 1 LIMIT 1");
}

// =============================================================================
// HELPERS
// =============================================================================

/**
 * Format date
 */
function format_date($date, $format = 'd.m.Y H:i')
{
    if (empty($date)) {
        return '';
    }
    if ($format === null || $format === '' || $format === 'd.m.Y H:i') {
        $time_format = get_setting('time_format', '24') === '12' ? 'g:i A' : 'H:i';
        $format = 'd.m.Y ' . $time_format;
    }
    return date($format, strtotime($date));
}

/**
 * Get localized month names (short and full) for the current language.
 */
function get_localized_months()
{
    $lang = $_SESSION['lang'] ?? get_setting('default_language', 'en');

    $months_full = [
        'cs' => ['ledna','února','března','dubna','května','června','července','srpna','září','října','listopadu','prosince'],
        'de' => ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'],
        'es' => ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'],
        'it' => ['gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre'],
    ];
    $months_short = [
        'cs' => ['led','úno','bře','dub','kvě','čvn','čvc','srp','zář','říj','lis','pro'],
        'de' => ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'],
        'es' => ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'],
        'it' => ['gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic'],
    ];
    $days_full = [
        'cs' => ['neděle','pondělí','úterý','středa','čtvrtek','pátek','sobota'],
        'de' => ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'],
        'es' => ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'],
        'it' => ['domenica','lunedì','martedì','mercoledì','giovedì','venerdì','sabato'],
    ];

    return [
        'full' => $months_full[$lang] ?? null,
        'short' => $months_short[$lang] ?? null,
        'days' => $days_full[$lang] ?? null,
    ];
}

/**
 * Format a date with localized month/day names.
 * Supports: 'M j' (short month + day), 'l, F j, Y' (full day, full month day, year), 'd. F Y' (day. month year)
 */
function format_date_localized($date, $format = 'd. F Y')
{
    $ts = is_numeric($date) ? (int)$date : strtotime($date);
    if (!$ts) return '';

    $loc = get_localized_months();

    $month_idx = (int)date('n', $ts) - 1; // 0-based
    $day_idx = (int)date('w', $ts);        // 0=Sun

    $result = date($format, $ts);

    // Replace English month names with localized versions
    if ($loc['full']) {
        $en_months_full = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        $result = str_replace($en_months_full[$month_idx], $loc['full'][$month_idx], $result);
    }
    if ($loc['short']) {
        $en_months_short = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $result = str_replace($en_months_short[$month_idx], $loc['short'][$month_idx], $result);
    }
    if ($loc['days']) {
        $en_days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $result = str_replace($en_days[$day_idx], $loc['days'][$day_idx], $result);
    }

    return $result;
}

/**
 * Format duration in minutes
 */
function format_duration_minutes($minutes)
{
    $minutes = max(0, (int) $minutes);
    $hours = (int) floor($minutes / 60);
    $mins = $minutes % 60;
    if ($hours > 0) {
        return $hours . 'h ' . $mins . 'min';
    }
    return $mins . ' min';
}

/**
 * Get configured currency label.
 */
function get_currency_label()
{
    $currency = trim((string) get_setting('currency', 'CZK'));
    return $currency !== '' ? $currency : 'CZK';
}

/**
 * Get billing rounding increment (minutes).
 */
function get_billing_rounding_increment()
{
    $value = (int) get_setting('billing_rounding', 15);
    return $value > 0 ? $value : 1;
}

/**
 * Round minutes to nearest increment.
 */
function round_minutes_nearest($minutes, $increment)
{
    $minutes = max(0, (int) $minutes);
    $increment = max(1, (int) $increment);
    return (int) ceil($minutes / $increment) * $increment;
}

/**
 * Format money with currency.
 */
function format_money($amount)
{
    $currency = get_currency_label();
    $value = number_format((float) $amount, 2, '.', ' ');
    return $value . ' ' . $currency;
}

/**
 * Resolve time range bounds from presets or custom dates.
 */
function get_time_range_bounds($range, $from_date = '', $to_date = '')
{
    $allowed = ['all', 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'custom'];
    $range = in_array($range, $allowed, true) ? $range : 'all';
    $start = null;
    $end = null;

    if ($range === 'today') {
        $start = new DateTime('today');
        $start->setTime(0, 0, 0);
        $end = new DateTime('now');
    } elseif ($range === 'yesterday') {
        $start = new DateTime('yesterday');
        $start->setTime(0, 0, 0);
        $end = new DateTime('yesterday');
        $end->setTime(23, 59, 59);
    } elseif ($range === 'this_week') {
        $start = new DateTime('monday this week');
        $start->setTime(0, 0, 0);
        $end = new DateTime('now');
    } elseif ($range === 'last_week') {
        $start = new DateTime('monday last week');
        $start->setTime(0, 0, 0);
        $end = new DateTime('sunday last week');
        $end->setTime(23, 59, 59);
    } elseif ($range === 'this_month') {
        $start = new DateTime('first day of this month');
        $start->setTime(0, 0, 0);
        $end = new DateTime('now');
    } elseif ($range === 'last_month') {
        $start = new DateTime('first day of last month');
        $start->setTime(0, 0, 0);
        $end = new DateTime('last day of last month');
        $end->setTime(23, 59, 59);
    } elseif ($range === 'last_30_days') {
        $start = new DateTime('-30 days');
        $start->setTime(0, 0, 0);
        $end = new DateTime('now');
    } elseif ($range === 'last_7_days') {
        $start = new DateTime('-7 days');
        $start->setTime(0, 0, 0);
        $end = new DateTime('now');
    } elseif ($range === 'this_quarter') {
        $month = (int) date('n');
        $quarter_start_month = (int) (floor(($month - 1) / 3) * 3 + 1);
        $start = new DateTime(date('Y') . '-' . str_pad((string)$quarter_start_month, 2, '0', STR_PAD_LEFT) . '-01');
        $start->setTime(0, 0, 0);
        $end = new DateTime('now');
    } elseif ($range === 'last_quarter') {
        $month = (int) date('n');
        $quarter_start_month = (int) (floor(($month - 1) / 3) * 3 + 1);
        $last_q_end = new DateTime(date('Y') . '-' . str_pad((string)$quarter_start_month, 2, '0', STR_PAD_LEFT) . '-01');
        $last_q_end->modify('-1 day');
        $last_q_end->setTime(23, 59, 59);
        $last_q_start_month = (int) (floor(((int)$last_q_end->format('n') - 1) / 3) * 3 + 1);
        $start = new DateTime($last_q_end->format('Y') . '-' . str_pad((string)$last_q_start_month, 2, '0', STR_PAD_LEFT) . '-01');
        $start->setTime(0, 0, 0);
        $end = $last_q_end;
    } elseif ($range === 'this_year') {
        $start = new DateTime('first day of January this year');
        $start->setTime(0, 0, 0);
        $end = new DateTime('now');
    } elseif ($range === 'last_year') {
        $start = new DateTime('first day of January last year');
        $start->setTime(0, 0, 0);
        $end = new DateTime('last day of December last year');
        $end->setTime(23, 59, 59);
    } elseif ($range === 'custom') {
        $from_date = trim((string) $from_date);
        $to_date = trim((string) $to_date);
        $start = DateTime::createFromFormat('Y-m-d', $from_date);
        $end = DateTime::createFromFormat('Y-m-d', $to_date);
        if (!$start || !$end) {
            $range = 'all';
            $start = null;
            $end = null;
        } else {
            $start->setTime(0, 0, 0);
            $end->setTime(23, 59, 59);
            if ($start > $end) {
                $tmp = $start;
                $start = $end;
                $end = $tmp;
            }
        }
    }

    $start_str = $start ? $start->format('Y-m-d H:i:s') : null;
    $end_str = $end ? $end->format('Y-m-d H:i:s') : null;

    return [
        'range' => $range,
        'start' => $start_str,
        'end' => $end_str
    ];
}

/**
 * Format file size
 */
function format_file_size($bytes)
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

/**
 * Check if a MIME type is a previewable image.
 */
function is_image_mime(string $mime_type): bool
{
    return in_array($mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
}

/**
 * Get file icon based on mime type
 */
function get_file_icon($mime_type)
{
    if (strpos($mime_type, 'image/') === 0) {
        return 'fa-file-image';
    } elseif ($mime_type === 'application/pdf') {
        return 'fa-file-pdf';
    } elseif (strpos($mime_type, 'word') !== false) {
        return 'fa-file-word';
    } elseif (strpos($mime_type, 'excel') !== false || strpos($mime_type, 'spreadsheet') !== false) {
        return 'fa-file-excel';
    } elseif ($mime_type === 'text/plain') {
        return 'fa-file-alt';
    } elseif (strpos($mime_type, 'zip') !== false || strpos($mime_type, 'rar') !== false) {
        return 'fa-file-archive';
    } else {
        return 'fa-file';
    }
}

/**
 * Log debug message to database
 * 
 * @param string $message Log message
 * @param mixed $context Additional data (array/object)
 * @param string $level Log level (info, warning, error, debug)
 * @param string $channel Log channel (general, request, auth, etc)
 * @return bool Success
 */
function debug_log($message, $context = [], $level = 'info', $channel = 'general')
{
    // Convert context to JSON if not a string
    if (is_array($context) || is_object($context)) {
        $context = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // Get current user if logged in
    $user_id = null;
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

    try {
        // Check if table exists (to avoid errors during install/upgrade)
        static $table_checked = false;
        if (!$table_checked) {
            $check = db_fetch_one("SHOW TABLES LIKE 'debug_log'");
            if (!$check) {
                return false;
            }
            $table_checked = true;
        }

        db_insert('debug_log', [
            'channel' => $channel,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'user_id' => $user_id,
            'ip_address' => $ip_address,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        return true;
    } catch (Exception $e) {
        // Fallback to error log
        error_log("Failed to write to debug_log: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if page_views table exists (with static cache).
 */
function page_views_table_exists()
{
    static $exists = null;
    if ($exists === null) {
        $exists = table_exists('page_views');
    }
    return $exists;
}

/**
 * Ensure page_views table exists — creates it on first call if missing.
 */
function ensure_page_views_table()
{
    if (page_views_table_exists()) {
        return true;
    }
    try {
        get_db()->exec("
            CREATE TABLE IF NOT EXISTS page_views (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                page VARCHAR(50) NOT NULL,
                section VARCHAR(50) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_page (page),
                INDEX idx_created (created_at),
                INDEX idx_user_page (user_id, page)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
