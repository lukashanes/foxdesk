<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/settings/settings-email.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$keys = settings_email_action_keys();
foreach (['save_email', 'test_smtp', 'test_imap', 'run_imap_now', 'save_template'] as $key) {
    $assert(in_array($key, $keys, true), 'Email settings action key missing: ' . $key);
    $assert(settings_is_email_action([$key => '1']), 'Email settings action detector failed: ' . $key);
}
$assert(!settings_is_email_action(['save_general' => '1']), 'Email detector must not match non-email actions.');

echo "Settings email contract OK\n";
