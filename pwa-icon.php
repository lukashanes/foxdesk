<?php
/**
 * PWA Icon Generator
 * Renders app icon as SVG, matching the favicon style.
 * Usage: pwa-icon.php?s=192 or pwa-icon.php?s=512
 */
define('BASE_PATH', __DIR__);
if (!file_exists(BASE_PATH . '/config.php')) { http_response_code(404); exit; }
require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/database.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/settings-functions.php';

$settings = get_settings();
$size = max(48, min(1024, (int)($_GET['s'] ?? 512)));
$app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');
$letter = strtoupper(mb_substr($app_name, 0, 1));
$primary_color = $settings['primary_color'] ?? '#3b82f6';

header('Content-Type: image/svg+xml');
header('Cache-Control: public, max-age=86400');

$font_size = round($size * 0.55);
$text_y = round($size * 0.68);
$rx = round($size * 0.1875);

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?php echo $size; ?>" height="<?php echo $size; ?>" viewBox="0 0 <?php echo $size; ?> <?php echo $size; ?>">
  <rect width="<?php echo $size; ?>" height="<?php echo $size; ?>" rx="<?php echo $rx; ?>" fill="<?php echo htmlspecialchars($primary_color); ?>"/>
  <text x="<?php echo $size / 2; ?>" y="<?php echo $text_y; ?>" font-family="Arial, Helvetica, sans-serif" font-size="<?php echo $font_size; ?>" font-weight="bold" fill="white" text-anchor="middle"><?php echo htmlspecialchars($letter); ?></text>
</svg>
