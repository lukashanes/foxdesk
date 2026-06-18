<?php

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$matrix_path = $root . '/docs/EDITION_PARITY_MATRIX.md';
$assert(is_file($matrix_path), 'Self-hosted edition parity matrix must exist.');

$matrix = file_get_contents($matrix_path);
$assert($matrix !== false, 'Unable to read self-hosted edition parity matrix.');

foreach ([
    '| Work | shared |',
    '| Inbox | shared |',
    '| Tickets | shared |',
    '| Ticket detail | shared |',
    '| New ticket | shared |',
    '| Clients | shared |',
    '| Reports | shared |',
    '| Search | shared |',
    '| Notifications | shared |',
    '| Email rendering | shared |',
    '| Installer | self-hosted |',
    '| Public updater | self-hosted |',
    '| Migration source | self-hosted |',
    '| Billing | saas |',
    '| Platform console | saas |',
] as $needle) {
    $assert(str_contains($matrix, $needle), 'Edition parity matrix is missing classification: ' . $needle);
}

foreach ([
    'pages/admin/migration-export.php',
    'install.php',
    'upgrade.php',
] as $route) {
    $assert(is_file($root . '/' . $route), 'Self-hosted repository must own ' . $route . '.');
}

foreach ([
    'pages/platform.php',
    'pages/billing.php',
    'pages/cloud.php',
    'pages/signup.php',
    'pages/stripe-webhook.php',
] as $route) {
    $assert(!is_file($root . '/' . $route), 'Self-hosted repository must not expose SaaS-only route ' . $route . '.');
    $assert(str_contains($matrix, $route), 'Self-hosted exclusion list must name ' . $route . '.');
}

foreach ([
    '`mine`, `unassigned`, `overdue`, `waiting`, `done_today`',
    '`triage`, `customer_replies`, `email_imports`',
    '`open`, `waiting`, `done`, `all`, `archived`',
    'No random client fallback',
    'One user action creates at most one meaningful email',
] as $needle) {
    $assert(str_contains($matrix, $needle), 'Self-hosted parity matrix is missing shared behavior: ' . $needle);
}

echo "Self-hosted edition parity contract OK\n";
