<?php

$root = dirname(__DIR__);

$module = file_get_contents($root . '/includes/modules/reports/reporting-flow.php');
$billing = file_get_contents($root . '/includes/modules/reports/billing-review.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$reports = file_get_contents($root . '/pages/admin/reports.php');
$billing_js = file_get_contents($root . '/assets/js/report-billing-review.js');
$builder = file_get_contents($root . '/pages/admin/report-builder.php');
$theme = file_get_contents($root . '/theme.css');

if ($module === false || $billing === false || $bootstrap === false || $reports === false || $builder === false || $theme === false) {
    fwrite(STDERR, "Unable to read reporting flow files.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(str_contains($bootstrap, '/reports/reporting-flow.php'), 'Module bootstrap must load reporting flow.');
$assert(str_contains($bootstrap, '/reports/billing-review.php'), 'Module bootstrap must load billing review.');
$assert(str_contains($module, 'function reporting_flow_steps'), 'Reporting flow steps helper is missing.');
$assert(str_contains($module, 'function reporting_flow_time_presets'), 'Reporting flow time presets helper is missing.');
$assert(str_contains($module, 'function reporting_flow_builder_url'), 'Reporting flow builder URL helper is missing.');
$assert(str_contains($billing, 'function billing_review_adjusted_rate'), 'Billing review adjustment helper is missing.');
$assert(str_contains($billing, 'function billing_review_rate_from_target_amount'), 'Billing review target total helper is missing.');
$assert(str_contains($billing, "'discount_amount'"), 'Billing review must support amount discounts.');
$assert(str_contains($reports, 'class="reporting-flow-card"'), 'Reports page must render the compact billing review flow.');
$assert(str_contains($reports, '$page_header_suppressed = true;'), 'Reports page must suppress the duplicate page header for laptop density.');
$assert(str_contains($reports, 'name="organizations[]"'), 'Billing review must submit the selected client as a report filter.');
$assert(str_contains($reports, 'name="tab" value="billing"'), 'Billing review must open the billing review mode.');
$assert(str_contains($reports, 'name="show_money" value="1"'), 'Billing review must show money columns.');
$assert(str_contains($reports, 'reporting_flow_steps()'), 'Reports page must render workflow steps from the helper.');
$assert(str_contains($reports, 'reporting_flow_builder_url('), 'Create report link must preserve selected client and period.');
$assert(str_contains($reports, 'billing_review_adjustment_actions()'), 'Detailed rows must use shared item adjustment actions.');
$assert(str_contains($reports, 'billing_review_bulk_adjustment_actions()'), 'Bulk adjustment form must use shared actions.');
$assert(str_contains($reports, 'name="bulk_discount_amount"'), 'Bulk billing review must allow amount discounts.');
$assert(str_contains($reports, 'data-entry-amount'), 'Detailed rows must expose row amounts for live totals.');
$assert(str_contains($reports, 'detail-billable-amount'), 'Detailed report must expose a live billable total.');
$assert($billing_js !== false && str_contains($billing_js, "selectedAction === 'discount_amount'"), 'Live totals must handle amount discounts.');
$assert(str_contains($reports, 'class="report-mode-link'), 'Reports page must use the simplified report mode switch.');
$assert(str_contains($reports, "'time' => t('Time overview')"), 'Reports page must expose Time overview as the first mode.');
$assert(str_contains($reports, "\$tab_labels['billing'] = t('Billing review')"), 'Reports page must expose Billing review only for admins.');
$assert(str_contains($reports, "\$tab_labels['published'] = t('Published reports')"), 'Reports page must expose Published reports only for admins.');
$assert(str_contains($builder, '$_GET[\'organization_id\']'), 'Report builder must accept a prefilled organization.');
$assert(str_contains($builder, '$_GET[\'date_from\']'), 'Report builder must accept a prefilled start date.');
$assert(str_contains($builder, '$_GET[\'date_to\']'), 'Report builder must accept a prefilled end date.');
$assert(str_contains($theme, '.reporting-flow-card'), 'Reporting flow styling is missing.');
$assert(str_contains($theme, '.workflow-surface') && str_contains($theme, 'grid-template-columns: minmax(0, 1fr);'), 'Workflow surfaces must use a shrink-safe grid column.');
$assert(str_contains($theme, 'width: 100%;') && str_contains($theme, 'min-width: 0;'), 'Reporting flow card must shrink inside the workspace shell.');
$assert(str_contains($theme, '@media (max-width: 1280px)') && str_contains($theme, '.reporting-flow-side'), 'Reporting flow must collapse before it can overflow the workspace viewport.');
$assert(str_contains($theme, '.reporting-flow-step strong') && str_contains($theme, 'overflow-wrap: anywhere;'), 'Reporting flow step labels must wrap instead of pushing the card off screen.');
$assert(str_contains($theme, '.workflow-surface--reports.admin-legacy-page') && str_contains($theme, 'gap: 0.625rem;'), 'Reports surface must use the compact laptop density layer.');
$assert(str_contains($theme, '.workflow-surface--reports .report-filter-grid') && str_contains($theme, 'grid-template-columns: repeat(4, minmax(0, 1fr));'), 'Report filters must use a compact grid on desktop.');
$assert(str_contains($theme, '@media (min-width: 981px) and (max-width: 1280px)') && str_contains($theme, 'grid-template-columns: repeat(4, minmax(0, 1fr));'), 'Report flow steps must stay compact on MacBook-width screens.');

echo "Reporting flow contract OK\n";
