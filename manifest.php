<?php
/**
 * PWA Web App Manifest (dynamic)
 * Generates manifest.json using app settings (name, colors, logo).
 */
define('BASE_PATH', __DIR__);
if (!file_exists(BASE_PATH . '/config.php')) { http_response_code(404); exit; }
require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/database.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/settings-functions.php';

$settings = get_settings();
$app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');
$primary_color = $settings['primary_color'] ?? '#3b82f6';
$bg_color = '#1e293b';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=3600');

echo json_encode([
    'name' => $app_name,
    'short_name' => $app_name,
    'description' => $app_name . ' - Helpdesk',
    'start_url' => 'index.php?page=dashboard',
    'display' => 'standalone',
    'background_color' => $bg_color,
    'theme_color' => $primary_color,
    'orientation' => 'any',
    'icons' => [
        [
            'src' => 'pwa-icon.php?s=192',
            'sizes' => '192x192',
            'type' => 'image/svg+xml',
            'purpose' => 'any',
        ],
        [
            'src' => 'pwa-icon.php?s=512',
            'sizes' => '512x512',
            'type' => 'image/svg+xml',
            'purpose' => 'any maskable',
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
