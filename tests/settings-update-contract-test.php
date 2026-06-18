<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/settings/settings-updates.php';
require_once $root . '/includes/modules/settings/settings-security.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

foreach (['check_updates_now', 'install_remote_update', 'upload_update', 'apply_update', 'create_backup', 'delete_backup'] as $key) {
    $assert(settings_is_update_action([$key => '1']), 'Update settings action detector failed: ' . $key);
}
$assert(settings_is_managed_update_action(['apply_update' => '1']), 'Managed update detector must catch apply_update.');
$assert(!settings_is_managed_update_action(['create_backup' => '1']), 'Managed update detector must not catch backup-only action.');

foreach (['save_2fa_settings', 'clear_logs', 'clear_security_logs'] as $key) {
    $assert(settings_is_security_action([$key => '1']), 'Security settings action detector failed: ' . $key);
}

echo "Settings update contract OK\n";
