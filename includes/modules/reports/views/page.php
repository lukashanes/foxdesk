<?php
// Self-hosted reports page composition. Business data is prepared by the controller.
$report_context = $context;
$report_partial = match ($tab) {
    'billing' => 'billing',
    'weekly' => 'weekly',
    'worklog' => 'worklog',
    'rates' => 'rates',
    'published' => 'published',
    default => 'time',
};
?>
<?php
$page_header_title = $page_title;
$page_header_suppressed = true;
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="workflow-surface workflow-surface--reports admin-legacy-page" data-core-workflow-surface="reports">
    <?php if (is_admin()): ?>
    <section class="reporting-flow-card" data-report-generation-card>
        <div class="reporting-flow-main">
            <div class="reporting-flow-heading">
                <h2><?php echo e(t('Reports')); ?></h2>
                <p><?php echo e(t('Time Reports')); ?></p>
            </div>
            <form method="GET" action="index.php" class="reporting-flow-form" data-report-create-form>
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="section" value="reports">
                <input type="hidden" name="tab" value="billing">
                <input type="hidden" name="show_money" value="1">
                <label>
                    <span><?php echo e(t('Client')); ?></span>
                    <select name="organizations[]" class="form-select" data-report-client-select>
                        <option value="" <?php echo $selected_flow_org === null ? 'selected' : ''; ?>>
                            <?php echo e(t('All')); ?>
                        </option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo (int) $org['id']; ?>"
                                <?php echo in_array((int) $org['id'], $selected_orgs, true) ? 'selected' : ''; ?>>
                                <?php echo e($org['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php echo e(t('Agent')); ?></span>
                    <select name="agents[]" class="form-select" data-report-agent-select>
                        <option value="" <?php echo $selected_flow_agent === null ? 'selected' : ''; ?>>
                            <?php echo e(t('All')); ?>
                        </option>
                        <?php foreach ($agents as $agent): ?>
                            <?php $agent_name = trim((string) $agent['first_name'] . ' ' . (string) $agent['last_name']); ?>
                            <option value="<?php echo (int) $agent['id']; ?>"
                                <?php echo (int) $agent['id'] === $selected_flow_agent ? 'selected' : ''; ?>>
                                <?php echo e($agent_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php echo e(t('Period')); ?></span>
                    <select name="time_range" class="form-select" data-report-period-select>
                        <?php foreach (reporting_flow_time_presets() as $preset => $label): ?>
                            <option value="<?php echo e($preset); ?>" <?php echo $time_range === $preset ? 'selected' : ''; ?>>
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary btn-sm">
                    <?php echo get_icon('search', 'w-3.5 h-3.5'); ?><?php echo e(t('Review work')); ?>
                </button>
            </form>
        </div>
        <div class="reporting-flow-side">
            <div class="reporting-flow-steps">
                <?php foreach (reporting_flow_steps() as $index => $step): ?>
                    <div class="reporting-flow-step">
                        <span><?php echo (int) $index + 1; ?></span>
                        <strong><?php echo e($step['label']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="admin-hero-actions">
            <a href="<?php echo url('admin', ['section' => 'reports-list']); ?>"
                class="btn btn-secondary btn-sm">
                <?php echo get_icon('list', 'w-3.5 h-3.5'); ?><?php echo e(t('Client reports')); ?>
            </a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <div class="report-page-toolbar report-page-toolbar--modes">
        <div class="report-mode-switch" aria-label="<?php echo e(t('Report mode')); ?>">
            <?php
            $tab_labels = [
                'time' => t('Time overview'),
            ];
            if (is_admin()) {
                $tab_labels['billing'] = t('Billing review');
                $tab_labels['published'] = t('Published reports');
            }
            foreach ($tab_labels as $tab_key => $label):
                $params = $base_params;
                $params['tab'] = $tab_key;
                $tab_url = 'index.php?' . http_build_query($params);
                ?>
                <a href="<?php echo e($tab_url); ?>"
                    class="report-mode-link <?php echo $tab === $tab_key ? 'is-active' : ''; ?>">
                    <?php echo e($label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($tab === 'time' && !empty($entries)): ?>
        <div class="report-actions">
            <a href="index.php?<?php echo http_build_query($report_export_params); ?>"
                class="report-mini-action"
                title="<?php echo e(t('Export CSV')); ?>">
                <?php echo get_icon('download', 'w-3 h-3 inline-block'); ?><?php echo e(t('Export CSV')); ?>
            </a>

            <!-- Print -->
            <button type="button" onclick="window.print()"
                class="report-mini-action"
                title="<?php echo e(t('Print')); ?>">
                <?php echo get_icon('print', 'w-3 h-3 inline-block'); ?><?php echo e(t('Print')); ?>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$time_tracking_available): ?>
        <div class="card card-body text-theme-secondary">
            <?php echo e(t('Time tracking is not available.')); ?>
        </div>
    <?php else: ?>
        <?php report_render_partial('filters', $report_context); ?>
        <?php report_render_partial($report_partial, $report_context); ?>
    <?php endif; ?>
</div>

<?php if ($tab === 'billing' || $tab === 'worklog'): ?>
    <?php report_render_partial('entry-modal', $report_context); ?>
<?php endif; ?>

<script>
window.FoxDeskReportPageConfig = <?php echo json_encode(
    $report_page_config,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
); ?>;
</script>
<?php
$report_page_asset_version = static function (string $path): string {
    $base_version = defined('APP_VERSION') ? (string) APP_VERSION : '1';
    $absolute_path = BASE_PATH . '/' . ltrim($path, '/');
    return $base_version . '-' . (string) (@filemtime($absolute_path) ?: '0');
};
?>
<script src="assets/js/chip-select.js?v=<?php echo e($report_page_asset_version('assets/js/chip-select.js')); ?>"></script>
<script src="assets/js/report-page.js?v=<?php echo e($report_page_asset_version('assets/js/report-page.js')); ?>"></script>
<script src="assets/js/report-billing-review.js?v=<?php echo e($report_page_asset_version('assets/js/report-billing-review.js')); ?>"></script>
<script src="assets/js/report-time-delete.js?v=<?php echo e($report_page_asset_version('assets/js/report-time-delete.js')); ?>"></script>
<link rel="stylesheet" href="assets/css/report-page.css?v=<?php echo e($report_page_asset_version('assets/css/report-page.css')); ?>">
