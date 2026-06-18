<?php

$root = dirname(__DIR__);

function assert_product_copy(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function read_product_file(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assert_product_copy($contents !== false, 'Unable to read ' . $path);
    return $contents;
}

$customer_files = [
    'pages/work.php',
    'pages/inbox.php',
    'includes/components/workspace-surface.php',
    'includes/modules/reports/reporting-flow.php',
];

foreach ($customer_files as $path) {
    $contents = read_product_file($root, $path);
    assert_product_copy(
        !preg_match('/[ěščřžýáíéůúďťňĚŠČŘŽÝÁÍÉŮÚĎŤŇ]/u', $contents),
        'Customer-facing source copy must stay English outside includes/lang: ' . $path
    );
}

$reporting = read_product_file($root, 'includes/modules/reports/reporting-flow.php');
foreach ([
    'Pick a client and period.',
    'Check billable rows.',
    'Tune rates, discounts, or totals.',
    'Send the final report.',
] as $needle) {
    assert_product_copy(str_contains($reporting, $needle), 'Reporting flow is missing concise source copy: ' . $needle);
}
foreach ([
    'Start with one client and one billing period.',
    'Open the detailed report with money columns visible.',
    'Create a client-facing report when the numbers are final.',
] as $forbidden) {
    assert_product_copy(!str_contains($reporting, $forbidden), 'Reporting flow still contains verbose copy: ' . $forbidden);
}

$settings = read_product_file($root, 'pages/admin/settings.php');
assert_product_copy(
    !str_contains($settings, 'Versions, updates, backups, background tasks, and upload limits in one place.'),
    'System settings must not use generic one-place copy.'
);
assert_product_copy(
    str_contains($settings, 'Versions, updates, backups, background tasks, and upload limits.'),
    'System settings must keep concise operations copy.'
);

echo "Source language and product copy contract OK\n";
