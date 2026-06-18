<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$page = file_get_contents($root . '/pages/admin/reports.php');
$asset = file_get_contents($root . '/assets/js/report-billing-review.js');

$assert($page !== false && $asset !== false, 'Report page and billing review JS asset must be readable.');
$assert(str_contains($page, 'assets/js/report-billing-review.js'), 'Reports page must load the extracted billing review JS asset.');
$assert(str_contains($page, 'data-report-currency='), 'Reporting review surface must expose a currency data attribute.');
$assert(!str_contains($page, 'function bulkPreviewForRow'), 'Reports page must not own billing preview JS.');
$assert(!str_contains($page, 'function rowPreview(row)'), 'Reports page must not own row preview JS.');
$assert(!str_contains($page, "selectedAction === 'target_total'"), 'Reports page must not own bulk target-total preview logic.');

foreach ([
    'function rowPreview(row)',
    'function bulkPreviewForRow',
    "selectedAction === 'discount_amount'",
    "selectedAction === 'target_total'",
    'dataset.reportCurrency',
    'detail-billable-amount',
    'detail-profit',
    'updatePreview();',
] as $needle) {
    $assert(str_contains($asset, $needle), 'Billing review JS asset missing: ' . $needle);
}

echo "Report billing review JS contract OK\n";
