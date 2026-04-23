<?php
/**
 * Report Builder - Create Client-Facing Time Reports
 * Admin-only interface for generating professional reports
 */

if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$current_user = current_user();
$allowed_report_languages = ['en', 'cs', 'de', 'it', 'es'];
$allowed_group_by = ['none', 'day', 'task'];
$allowed_rounding = [0, 15, 30, 60];

// Editing mode: load existing report
$editing = false;
$edit_report = null;
$edit_id = (int) ($_GET['edit'] ?? 0);
if ($edit_id > 0) {
    $edit_report = get_report_template($edit_id);
    if ($edit_report) {
        $editing = true;
    }
}
$page_title = $editing ? t('Edit Client Report') : t('Create Client Report');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $organization_id = (int) ($_POST['organization_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $report_language = trim((string) ($_POST['report_language'] ?? 'en'));
    if (!in_array($report_language, $allowed_report_languages, true)) {
        $report_language = 'en';
    }
    $date_from = trim((string) ($_POST['date_from'] ?? ''));
    $date_to = trim((string) ($_POST['date_to'] ?? ''));
    $group_by = trim((string) ($_POST['group_by'] ?? 'none'));
    if (!in_array($group_by, $allowed_group_by, true)) {
        $group_by = 'none';
    }
    $rounding_minutes = (int) ($_POST['rounding_minutes'] ?? 15);
    if (!in_array($rounding_minutes, $allowed_rounding, true)) {
        $rounding_minutes = 15;
    }
    $custom_billable_rate_raw = trim((string) ($_POST['custom_billable_rate'] ?? ''));
    $custom_billable_rate = $custom_billable_rate_raw !== ''
        ? max(0, (float) str_replace(',', '.', $custom_billable_rate_raw))
        : null;
    $theme_color = trim((string) ($_POST['theme_color'] ?? ''));
    if ($theme_color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $theme_color)) {
        $theme_color = '';
    }
    $schedule_interval = in_array(($_POST['schedule_interval'] ?? ''), ['weekly', 'monthly', 'quarterly'], true)
        ? $_POST['schedule_interval']
        : 'monthly';
    $schedule_day_source = $schedule_interval === 'weekly'
        ? ($_POST['schedule_day'] ?? 1)
        : ($_POST['schedule_day_num'] ?? ($_POST['schedule_day'] ?? 1));
    $schedule_day = max(1, min($schedule_interval === 'weekly' ? 7 : 28, (int) $schedule_day_source));

    $report_data = [
        'organization_id' => $organization_id,
        'created_by_user_id' => current_user()['id'],
        'title' => $title,
        'report_language' => $report_language,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'executive_summary' => $_POST['executive_summary'] ?? '',
        'show_financials' => isset($_POST['show_financials']) ? 1 : 0,
        'show_team_attribution' => isset($_POST['show_team_attribution']) ? 1 : 0,
        'show_cost_breakdown' => isset($_POST['show_cost_breakdown']) ? 1 : 0,
        'custom_billable_rate' => $custom_billable_rate,
        'group_by' => $group_by,
        'rounding_minutes' => $rounding_minutes,
        'theme_color' => $theme_color !== '' ? $theme_color : null,
        'hide_branding' => isset($_POST['hide_branding']) ? 1 : 0,
        'is_draft' => isset($_POST['save_as_draft']) ? 1 : 0,
        'schedule_enabled' => isset($_POST['schedule_enabled']) ? 1 : 0,
        'schedule_interval' => $schedule_interval,
        'schedule_day' => $schedule_day,
        'schedule_recipients' => trim((string) ($_POST['schedule_recipients'] ?? '')),
    ];

    if (!empty($report_data['schedule_enabled']) && function_exists('calculate_next_report_due')) {
        ensure_report_schedule_columns();
        $report_data['schedule_next_due'] = calculate_next_report_due($report_data['schedule_interval'], $report_data['schedule_day']);
    } else {
        $report_data['schedule_next_due'] = null;
    }

    $validation_errors = [];
    if ($organization_id <= 0 || !get_organization($organization_id)) {
        $validation_errors[] = t('Selected organization is not available.');
    }
    if ($title === '') {
        $validation_errors[] = t('Please enter a report title.');
    }
    $date_from_obj = DateTime::createFromFormat('Y-m-d', $date_from);
    $date_to_obj = DateTime::createFromFormat('Y-m-d', $date_to);
    if (!$date_from_obj || !$date_to_obj) {
        $validation_errors[] = t('Please enter a valid date range.');
    } elseif ($date_from_obj > $date_to_obj) {
        $validation_errors[] = t('From Date must be before To Date.');
    }

    if (empty($validation_errors)) {
        $update_id = (int) ($_POST['edit_id'] ?? 0);

        if ($update_id > 0) {
            // Update existing report
            try {
                $success = update_report_template($update_id, $report_data);
            } catch (Throwable $e) {
                $success = false;
                error_log('Report builder update failed: ' . $e->getMessage());
            }

            if ($success) {
                flash(t('Report updated successfully.'), 'success');
                redirect('admin', ['section' => 'reports-list']);
            } else {
                flash(t('Failed to update report. Please try again.'), 'error');
            }
        } else {
            // Create new report
            try {
                $report_id = create_report_template($report_data);
            } catch (Throwable $e) {
                $report_id = false;
                error_log('Report builder create failed: ' . $e->getMessage());
            }

            if ($report_id) {
                if ($report_data['is_draft']) {
                    flash(t('Report draft saved successfully.'), 'success');
                } else {
                    $share_token = create_report_template_share($report_id, $report_data['organization_id']);

                    if ($share_token) {
                        flash(t('Report created successfully! Share link is ready in the Shared tab.'), 'success');
                    } else {
                        flash(t('Report created successfully!'), 'success');
                    }
                }

                redirect('admin', ['section' => 'reports-list']);
            } else {
                flash(t('Failed to create report. Please try again.'), 'error');
            }
        }
    } else {
        flash(implode(' ', $validation_errors), 'error');
    }
}

// Get organizations for dropdown
$organizations = db_fetch_all("SELECT id, name FROM organizations WHERE is_active = 1 ORDER BY name ASC");

// Get available languages
$languages = [
    'en' => t('English'),
    'cs' => t('Czech'),
    'de' => t('German'),
    'it' => t('Italian'),
    'es' => t('Spanish')
];

// Date presets
$today = date('Y-m-d');
$first_of_month = date('Y-m-01');
$last_of_month = date('Y-m-t');
$first_of_last_month = date('Y-m-01', strtotime('first day of last month'));
$last_of_last_month = date('Y-m-t', strtotime('last day of last month'));

// Set defaults from editing report or POST data
if ($editing && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form_values = [
        'organization_id' => (int) ($edit_report['organization_id'] ?? 0),
        'title' => trim((string) ($edit_report['title'] ?? '')),
        'report_language' => trim((string) ($edit_report['report_language'] ?? 'en')),
        'date_from' => trim((string) ($edit_report['date_from'] ?? $first_of_last_month)),
        'date_to' => trim((string) ($edit_report['date_to'] ?? $last_of_last_month)),
        'executive_summary' => (string) ($edit_report['executive_summary'] ?? ''),
        'group_by' => trim((string) ($edit_report['group_by'] ?? 'none')),
        'rounding_minutes' => (int) ($edit_report['rounding_minutes'] ?? 15),
        'theme_color' => trim((string) ($edit_report['theme_color'] ?? '#3B82F6')),
        'show_financials' => !empty($edit_report['show_financials']),
        'show_team_attribution' => !empty($edit_report['show_team_attribution']),
        'show_cost_breakdown' => !empty($edit_report['show_cost_breakdown']),
        'custom_billable_rate' => isset($edit_report['custom_billable_rate']) && $edit_report['custom_billable_rate'] !== null
            ? (float) $edit_report['custom_billable_rate']
            : null,
        'hide_branding' => !empty($edit_report['hide_branding']),
        'schedule_enabled' => !empty($edit_report['schedule_enabled']),
        'schedule_interval' => trim((string) ($edit_report['schedule_interval'] ?? 'monthly')),
        'schedule_day' => (int) ($edit_report['schedule_day'] ?? 1),
        'schedule_recipients' => trim((string) ($edit_report['schedule_recipients'] ?? '')),
    ];
} else {
    $form_values = [
        'organization_id' => (int) ($_POST['organization_id'] ?? 0),
        'title' => trim((string) ($_POST['title'] ?? '')),
        'report_language' => trim((string) ($_POST['report_language'] ?? 'en')),
        'date_from' => trim((string) ($_POST['date_from'] ?? $first_of_last_month)),
        'date_to' => trim((string) ($_POST['date_to'] ?? $last_of_last_month)),
        'executive_summary' => (string) ($_POST['executive_summary'] ?? ''),
        'group_by' => trim((string) ($_POST['group_by'] ?? 'none')),
        'rounding_minutes' => (int) ($_POST['rounding_minutes'] ?? 15),
        'theme_color' => trim((string) ($_POST['theme_color'] ?? '#3B82F6')),
        'show_financials' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['show_financials']) : true,
        'show_team_attribution' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['show_team_attribution']) : true,
        'show_cost_breakdown' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['show_cost_breakdown']) : false,
        'custom_billable_rate' => ($_POST['custom_billable_rate'] ?? '') !== ''
            ? max(0, (float) str_replace(',', '.', (string) $_POST['custom_billable_rate']))
            : null,
        'hide_branding' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['hide_branding']) : false,
        'schedule_enabled' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['schedule_enabled']) : false,
        'schedule_interval' => isset($schedule_interval) ? $schedule_interval : trim((string) ($_POST['schedule_interval'] ?? 'monthly')),
        'schedule_day' => isset($schedule_day) ? $schedule_day : (int) ($_POST['schedule_day'] ?? 1),
        'schedule_recipients' => trim((string) ($_POST['schedule_recipients'] ?? '')),
    ];
}
if (!in_array($form_values['report_language'], $allowed_report_languages, true)) {
    $form_values['report_language'] = 'en';
}
if (!in_array($form_values['group_by'], $allowed_group_by, true)) {
    $form_values['group_by'] = 'none';
}
if (!in_array($form_values['rounding_minutes'], $allowed_rounding, true)) {
    $form_values['rounding_minutes'] = 15;
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $form_values['theme_color'])) {
    $form_values['theme_color'] = '#3B82F6';
}

include BASE_PATH . '/includes/header.php';
?>

<div class="p-4 lg:p-8 max-w-5xl mx-auto">
    <!-- Page Header -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold" style="color: var(--text-primary);"><?php echo e($editing ? t('Edit Client Report') : t('Create Client Report')); ?></h1>
                <p class="mt-1 text-sm" style="color: var(--text-muted);">
                    <?php echo e($editing ? t('Update this client report') : t('Generate a professional time tracking report for your clients')); ?>
                </p>
            </div>
            <a href="<?php echo url('admin', ['section' => 'reports-list']); ?>" class="btn btn-secondary">
                <?php echo get_icon('arrow-left', 'w-4 h-4 mr-2 inline-block'); ?><?php echo e(t('Back to Reports')); ?>
            </a>
        </div>
    </div>

    <!-- Report Builder Form -->
    <form method="POST" action="" class="space-y-8">
        <?php echo csrf_field(); ?>
        <?php if ($editing): ?>
            <input type="hidden" name="edit_id" value="<?php echo (int) $edit_report['id']; ?>">
        <?php endif; ?>

        <!-- Step 1: Basic Information -->
        <div class="card card-body">
            <h2 class="text-xl font-semibold mb-4 flex items-center" style="color: var(--text-primary);">
                <span class="bg-blue-50 dark:bg-blue-900/200 text-white rounded-full w-8 h-8 flex items-center justify-center text-sm font-bold mr-3">1</span>
                <?php echo e(t('Basic Information')); ?>
            </h2>

            <div class="space-y-4">
                <!-- Organization Selector (Searchable) -->
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Client / Organization')); ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="hidden" name="organization_id" id="org-hidden-input" required
                        value="<?php echo e($form_values['organization_id'] ?? ''); ?>">
                    <div class="relative" id="org-search-wrapper">
                        <input type="text" id="org-search-input" class="form-input w-full pl-9"
                            placeholder="<?php echo e(t('Search organizations...')); ?>"
                            autocomplete="off"
                            value="<?php
                                $sel_org_name = '';
                                foreach ($organizations as $org) {
                                    if ((int) $form_values['organization_id'] === (int) $org['id']) {
                                        $sel_org_name = $org['name'];
                                        break;
                                    }
                                }
                                echo e($sel_org_name);
                            ?>">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2" style="color: var(--text-muted);"><?php echo get_icon('search', 'w-4 h-4'); ?></span>
                        <?php if (!empty($form_values['organization_id'])): ?>
                        <button type="button" id="org-clear-btn" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm" style="color: var(--text-muted);"
                            onclick="clearOrgSelection()">&times;</button>
                        <?php else: ?>
                        <button type="button" id="org-clear-btn" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden" style="color: var(--text-muted);"
                            onclick="clearOrgSelection()">&times;</button>
                        <?php endif; ?>
                        <div id="org-dropdown" class="absolute z-30 left-0 right-0 mt-1 rounded-lg shadow-lg border overflow-y-auto hidden"
                            style="max-height: 240px; background: var(--bg-primary); border-color: var(--border-light);">
                            <?php foreach ($organizations as $org): ?>
                                <div class="org-option px-4 py-2 text-sm cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700"
                                    data-value="<?php echo $org['id']; ?>"
                                    data-name="<?php echo e($org['name']); ?>"
                                    style="color: var(--text-primary);"
                                    onclick="selectOrg(this)">
                                    <?php echo e($org['name']); ?>
                                </div>
                            <?php endforeach; ?>
                            <div id="org-no-match" class="px-4 py-3 text-sm text-center hidden" style="color: var(--text-muted);">
                                <?php echo e(t('No organizations found')); ?>
                            </div>
                        </div>
                    </div>
                    <p class="mt-1 text-xs" style="color: var(--text-muted);"><?php echo e(t('Type to search, then click to select')); ?></p>
                </div>

                <!-- Report Title -->
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Report Title')); ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="title" required value="<?php echo e($form_values['title']); ?>"
                           placeholder="<?php echo e(t('e.g., January 2026 Time Report')); ?>"
                           class="form-input">
                </div>

                <!-- Language Selector -->
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Report Language')); ?>
                    </label>
                    <select name="report_language" class="form-input">
                        <?php foreach ($languages as $code => $name): ?>
                            <option value="<?php echo $code; ?>" <?php echo ($code === $form_values['report_language']) ? 'selected' : ''; ?>>
                                <?php echo e($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Step 2: Timeframe -->
        <div class="card card-body">
            <h2 class="text-xl font-semibold mb-4 flex items-center" style="color: var(--text-primary);">
                <span class="bg-blue-50 dark:bg-blue-900/200 text-white rounded-full w-8 h-8 flex items-center justify-center text-sm font-bold mr-3">2</span>
                <?php echo e(t('Timeframe')); ?>
            </h2>

            <div class="space-y-4">
                <!-- Date Presets -->
                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--text-secondary);"><?php echo e(t('Quick Presets')); ?></label>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="setDateRange('<?php echo $first_of_month; ?>', '<?php echo $last_of_month; ?>')"
                                class="btn btn-secondary btn-sm">
                            <?php echo e(t('This Month')); ?>
                        </button>
                        <button type="button" onclick="setDateRange('<?php echo $first_of_last_month; ?>', '<?php echo $last_of_last_month; ?>')"
                                class="btn btn-secondary btn-sm">
                            <?php echo e(t('Last Month')); ?>
                        </button>
                        <button type="button" onclick="setDateRange('<?php echo date('Y-01-01'); ?>', '<?php echo date('Y-12-31'); ?>')"
                                class="btn btn-secondary btn-sm">
                            <?php echo e(t('This Year')); ?>
                        </button>
                    </div>
                </div>

                <!-- Custom Date Range -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                            <?php echo e(t('From Date')); ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="date" name="date_from" id="date_from" required value="<?php echo e($form_values['date_from']); ?>"
                               class="form-input">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                            <?php echo e(t('To Date')); ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="date" name="date_to" id="date_to" required value="<?php echo e($form_values['date_to']); ?>"
                               class="form-input">
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Configuration -->
        <div class="card card-body">
            <h2 class="text-xl font-semibold mb-4 flex items-center" style="color: var(--text-primary);">
                <span class="bg-blue-50 dark:bg-blue-900/200 text-white rounded-full w-8 h-8 flex items-center justify-center text-sm font-bold mr-3">3</span>
                <?php echo e(t('Display Options')); ?>
            </h2>

            <div class="space-y-3">
                <!-- Toggle Switches -->
                <div class="space-y-3">
                    <!-- Show Financials -->
                    <label class="flex items-center">
                        <input type="checkbox" name="show_financials" <?php echo $form_values['show_financials'] ? 'checked' : ''; ?>
                               class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                        <span class="ml-3">
                            <span class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e(t('Show Financial Data')); ?></span>
                            <span class="block text-xs" style="color: var(--text-muted);"><?php echo e(t('Display hourly rates and total costs')); ?></span>
                        </span>
                    </label>

                    <div class="pl-7">
                        <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                            <?php echo e(t('Custom report rate (per hour)')); ?>
                        </label>
                        <input type="number" name="custom_billable_rate" step="0.01" min="0" class="form-input"
                               value="<?php echo e($form_values['custom_billable_rate'] !== null ? number_format((float) $form_values['custom_billable_rate'], 2, '.', '') : ''); ?>"
                               placeholder="<?php echo e(t('Leave empty to use ticket or client rates')); ?>">
                        <p class="mt-1 text-xs" style="color: var(--text-muted);">
                            <?php echo e(t('Apply one custom hourly rate to this report without changing ticket data.')); ?>
                        </p>
                    </div>

                    <!-- Show Team Attribution -->
                    <label class="flex items-center">
                        <input type="checkbox" name="show_team_attribution" <?php echo $form_values['show_team_attribution'] ? 'checked' : ''; ?>
                               class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                        <span class="ml-3">
                            <span class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e(t('Show Team Member Names')); ?></span>
                            <span class="block text-xs" style="color: var(--text-muted);"><?php echo e(t('Attribute work to specific team members')); ?></span>
                        </span>
                    </label>

                    <!-- Show Cost Breakdown -->
                    <label class="flex items-center">
                        <input type="checkbox" name="show_cost_breakdown" <?php echo $form_values['show_cost_breakdown'] ? 'checked' : ''; ?>
                               class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                        <span class="ml-3">
                            <span class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e(t('Show Detailed Cost Breakdown')); ?></span>
                            <span class="block text-xs" style="color: var(--text-muted);"><?php echo e(t('Show cost per task in the data table')); ?></span>
                        </span>
                    </label>

                    <!-- Hide Branding -->
                    <label class="flex items-center">
                        <input type="checkbox" name="hide_branding" <?php echo $form_values['hide_branding'] ? 'checked' : ''; ?>
                               class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                        <span class="ml-3">
                            <span class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e(t('White-Label Mode')); ?></span>
                            <span class="block text-xs" style="color: var(--text-muted);"><?php echo e(t('Hide "Powered by" footer branding')); ?></span>
                        </span>
                    </label>
                </div>

                <!-- Grouping Options -->
                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--text-secondary);"><?php echo e(t('Group Entries By')); ?></label>
                    <select name="group_by" class="form-input">
                        <option value="none" <?php echo $form_values['group_by'] === 'none' ? 'selected' : ''; ?>><?php echo e(t('No Grouping (Show all entries)')); ?></option>
                        <option value="day" <?php echo $form_values['group_by'] === 'day' ? 'selected' : ''; ?>><?php echo e(t('Group by Day')); ?></option>
                        <option value="task" <?php echo $form_values['group_by'] === 'task' ? 'selected' : ''; ?>><?php echo e(t('Group by Task')); ?></option>
                    </select>
                    <p class="mt-1 text-xs" style="color: var(--text-muted);"><?php echo e(t('Grouped entries can be expanded to see details')); ?></p>
                </div>

                <!-- Rounding Options -->
                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--text-secondary);"><?php echo e(t('Time Rounding')); ?></label>
                    <select name="rounding_minutes" class="form-input">
                        <option value="0" <?php echo $form_values['rounding_minutes'] === 0 ? 'selected' : ''; ?>><?php echo e(t('No Rounding (Exact time)')); ?></option>
                        <option value="15" <?php echo $form_values['rounding_minutes'] === 15 ? 'selected' : ''; ?>><?php echo e(t('Round to 15 minutes')); ?></option>
                        <option value="30" <?php echo $form_values['rounding_minutes'] === 30 ? 'selected' : ''; ?>><?php echo e(t('Round to 30 minutes')); ?></option>
                        <option value="60" <?php echo $form_values['rounding_minutes'] === 60 ? 'selected' : ''; ?>><?php echo e(t('Round to 1 hour')); ?></option>
                    </select>
                    <p class="mt-1 text-xs" style="color: var(--text-muted);"><?php echo e(t('Round up time for billing purposes')); ?></p>
                </div>

                <!-- Theme Color -->
                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--text-secondary);"><?php echo e(t('Report Theme Color')); ?></label>
                    <div class="flex items-center space-x-4">
                        <input type="color" name="theme_color" value="<?php echo e($form_values['theme_color']); ?>" id="theme_color"
                               class="w-32 h-16 border-2 rounded-lg cursor-pointer shadow-sm" style="border-color: var(--border-light);">
                        <div>
                            <p class="text-sm font-medium" style="color: var(--text-primary);" id="color_display"><?php echo e(strtoupper($form_values['theme_color'])); ?></p>
                            <p class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Click to choose a color')); ?></p>
                        </div>
                    </div>
                    <p class="mt-2 text-xs" style="color: var(--text-muted);"><?php echo e(t('Used for KPI cards, chart colors, and section accents')); ?></p>
                </div>
            </div>
        </div>

        <!-- Step 4: Executive Summary -->
        <div class="card card-body">
            <h2 class="text-xl font-semibold mb-4 flex items-center" style="color: var(--text-primary);">
                <span class="bg-blue-50 dark:bg-blue-900/200 text-white rounded-full w-8 h-8 flex items-center justify-center text-sm font-bold mr-3">4</span>
                <?php echo e(t('Executive Summary')); ?>
            </h2>

            <div>
                <label class="block text-sm font-medium mb-2" style="color: var(--text-secondary);">
                    <?php echo e(t('Write a custom message to your client')); ?>
                </label>
                <textarea name="executive_summary" id="executive_summary" rows="6"
                          placeholder="<?php echo e(t('Example: This month, our team focused on redesigning the user dashboard and implementing new analytics features...')); ?>"
                          class="form-input"><?php echo e($form_values['executive_summary']); ?></textarea>
                <p class="mt-1 text-xs" style="color: var(--text-muted);"><?php echo e(t('This text will appear at the top of the report')); ?></p>
            </div>
        </div>

        <!-- Step 5: Schedule & Email Delivery -->
        <?php ensure_report_schedule_columns(); ?>
        <div class="card card-body">
            <h2 class="text-xl font-semibold mb-4 flex items-center" style="color: var(--text-primary);">
                <span class="bg-blue-50 dark:bg-blue-900/200 text-white rounded-full w-8 h-8 flex items-center justify-center text-sm font-bold mr-3">5</span>
                <?php echo e(t('Schedule & Email Delivery')); ?>
                <span class="ml-2 text-xs font-normal px-2 py-0.5 rounded-full bg-blue-100 text-blue-700"><?php echo e(t('Optional')); ?></span>
            </h2>

            <div class="space-y-4">
                <!-- Enable Schedule -->
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" name="schedule_enabled" id="schedule_enabled"
                           <?php echo $form_values['schedule_enabled'] ? 'checked' : ''; ?>
                           class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500"
                           onchange="toggleScheduleFields()">
                    <span class="ml-3">
                        <span class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e(t('Enable automatic schedule')); ?></span>
                        <span class="block text-xs" style="color: var(--text-muted);"><?php echo e(t('Auto-regenerate this report and email it to recipients on a recurring schedule')); ?></span>
                    </span>
                </label>

                <div id="schedule-fields" class="<?php echo $form_values['schedule_enabled'] ? '' : 'hidden'; ?> space-y-4 pl-7 border-l-2 ml-2" style="border-color: var(--accent-primary);">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Interval -->
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Frequency')); ?></label>
                            <select name="schedule_interval" id="schedule_interval" class="form-input" onchange="updateScheduleDayLabel()">
                                <option value="weekly" <?php echo $form_values['schedule_interval'] === 'weekly' ? 'selected' : ''; ?>><?php echo e(t('Weekly')); ?></option>
                                <option value="monthly" <?php echo $form_values['schedule_interval'] === 'monthly' ? 'selected' : ''; ?>><?php echo e(t('Monthly')); ?></option>
                                <option value="quarterly" <?php echo $form_values['schedule_interval'] === 'quarterly' ? 'selected' : ''; ?>><?php echo e(t('Quarterly')); ?></option>
                            </select>
                        </div>

                        <!-- Day -->
                        <div>
                            <label class="block text-sm font-medium mb-1" id="schedule-day-label" style="color: var(--text-secondary);">
                                <?php echo e($form_values['schedule_interval'] === 'weekly' ? t('Day of Week') : t('Day of Month')); ?>
                            </label>
                            <!-- Day of week (for weekly) -->
                            <select name="schedule_day" id="schedule_day_select" class="form-input <?php echo $form_values['schedule_interval'] !== 'weekly' ? 'hidden' : ''; ?>">
                                <?php
                                $dow_names = [1 => t('Monday'), 2 => t('Tuesday'), 3 => t('Wednesday'), 4 => t('Thursday'), 5 => t('Friday'), 6 => t('Saturday'), 7 => t('Sunday')];
                                foreach ($dow_names as $dv => $dn): ?>
                                    <option value="<?php echo $dv; ?>" <?php echo $form_values['schedule_day'] === $dv ? 'selected' : ''; ?>><?php echo e($dn); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <!-- Day of month (for monthly/quarterly) -->
                            <input type="number" name="schedule_day_num" id="schedule_day_num" min="1" max="28"
                                   value="<?php echo min(28, max(1, $form_values['schedule_day'])); ?>"
                                   class="form-input <?php echo $form_values['schedule_interval'] === 'weekly' ? 'hidden' : ''; ?>">
                            <p class="text-xs mt-1" style="color: var(--text-muted);">
                                <span id="schedule-day-hint-weekly" class="<?php echo $form_values['schedule_interval'] !== 'weekly' ? 'hidden' : ''; ?>">
                                    <?php echo e(t('Report will be generated on this day each week')); ?>
                                </span>
                                <span id="schedule-day-hint-monthly" class="<?php echo $form_values['schedule_interval'] === 'weekly' ? 'hidden' : ''; ?>">
                                    <?php echo e(t('Report will be generated on this day each period (max 28)')); ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Recipients -->
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                            <?php echo get_icon('mail', 'w-4 h-4 inline-block mr-1'); ?><?php echo e(t('Email Recipients')); ?>
                        </label>
                        <textarea name="schedule_recipients" rows="2" class="form-input"
                                  placeholder="<?php echo e(t('client@example.com, manager@example.com')); ?>"><?php echo e($form_values['schedule_recipients']); ?></textarea>
                        <p class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e(t('Comma-separated email addresses. Each will receive the report with a summary and a link to the full report.')); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="flex items-center justify-between">
            <button type="submit" name="save_as_draft" value="1"
                    class="btn btn-secondary">
                <?php echo get_icon('save', 'w-4 h-4 mr-2 inline-block'); ?><?php echo e($editing ? t('Save as Draft') : t('Save as Draft')); ?>
            </button>

            <button type="submit"
                    class="btn btn-primary px-8 py-3 font-bold text-lg shadow-lg">
                <?php echo get_icon($editing ? 'check' : 'send', 'w-5 h-5 mr-2 inline-block'); ?><?php echo e($editing ? t('Update Report') : t('Generate Report')); ?>
            </button>
        </div>
    </form>
</div>

<script>
// Date range preset buttons
function setDateRange(from, to) {
    document.getElementById('date_from').value = from;
    document.getElementById('date_to').value = to;
}

// Schedule fields toggle
function toggleScheduleFields() {
    var enabled = document.getElementById('schedule_enabled').checked;
    document.getElementById('schedule-fields').classList.toggle('hidden', !enabled);
}
function updateScheduleDayLabel() {
    var interval = document.getElementById('schedule_interval').value;
    var isWeekly = (interval === 'weekly');
    var label = document.getElementById('schedule-day-label');
    label.textContent = isWeekly ? <?php echo json_encode(t('Day of Week')); ?> : <?php echo json_encode(t('Day of Month')); ?>;
    document.getElementById('schedule_day_select').classList.toggle('hidden', !isWeekly);
    document.getElementById('schedule_day_num').classList.toggle('hidden', isWeekly);
    document.getElementById('schedule-day-hint-weekly').classList.toggle('hidden', !isWeekly);
    document.getElementById('schedule-day-hint-monthly').classList.toggle('hidden', isWeekly);
}
// Sync schedule_day value on form submit
document.addEventListener('DOMContentLoaded', function() {
    var form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            var interval = document.getElementById('schedule_interval').value;
            if (interval !== 'weekly') {
                // Copy numeric day to hidden select so the 'schedule_day' name gets the right value
                document.getElementById('schedule_day_select').value = document.getElementById('schedule_day_num').value;
            }
        });
    }
});

// Update color display when color picker changes
document.addEventListener('DOMContentLoaded', function() {
    const colorPicker = document.getElementById('theme_color');
    const colorDisplay = document.getElementById('color_display');

    if (colorPicker && colorDisplay) {
        colorPicker.addEventListener('input', function() {
            colorDisplay.textContent = this.value.toUpperCase();
        });
    }
});
</script>

<!-- Organization Searchable Dropdown -->
<script>
(function() {
    var searchInput = document.getElementById('org-search-input');
    var hiddenInput = document.getElementById('org-hidden-input');
    var dropdown = document.getElementById('org-dropdown');
    var clearBtn = document.getElementById('org-clear-btn');
    var noMatch = document.getElementById('org-no-match');
    if (!searchInput || !dropdown) return;

    searchInput.addEventListener('focus', function() {
        dropdown.classList.remove('hidden');
        filterOrgs();
    });

    searchInput.addEventListener('input', function() {
        dropdown.classList.remove('hidden');
        filterOrgs();
        // If user types and changes the text, clear the selection
        if (hiddenInput.value && searchInput.value !== searchInput.dataset.selectedName) {
            hiddenInput.value = '';
            if (clearBtn) clearBtn.classList.add('hidden');
        }
    });

    function filterOrgs() {
        var query = searchInput.value.toLowerCase().trim();
        var options = dropdown.querySelectorAll('.org-option');
        var visible = 0;
        options.forEach(function(opt) {
            var name = (opt.dataset.name || '').toLowerCase();
            if (!query || name.indexOf(query) !== -1) {
                opt.style.display = '';
                visible++;
            } else {
                opt.style.display = 'none';
            }
        });
        if (noMatch) noMatch.classList.toggle('hidden', visible > 0);
    }

    // Close dropdown on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#org-search-wrapper')) {
            dropdown.classList.add('hidden');
            // If nothing selected, restore previous selection name
            if (hiddenInput.value && searchInput.dataset.selectedName) {
                searchInput.value = searchInput.dataset.selectedName;
            }
        }
    });

    // Store initial selected name
    if (hiddenInput.value && searchInput.value) {
        searchInput.dataset.selectedName = searchInput.value;
    }
})();

function selectOrg(el) {
    var hiddenInput = document.getElementById('org-hidden-input');
    var searchInput = document.getElementById('org-search-input');
    var dropdown = document.getElementById('org-dropdown');
    var clearBtn = document.getElementById('org-clear-btn');
    hiddenInput.value = el.dataset.value;
    searchInput.value = el.dataset.name;
    searchInput.dataset.selectedName = el.dataset.name;
    dropdown.classList.add('hidden');
    if (clearBtn) clearBtn.classList.remove('hidden');
}

function clearOrgSelection() {
    var hiddenInput = document.getElementById('org-hidden-input');
    var searchInput = document.getElementById('org-search-input');
    var clearBtn = document.getElementById('org-clear-btn');
    hiddenInput.value = '';
    searchInput.value = '';
    searchInput.dataset.selectedName = '';
    if (clearBtn) clearBtn.classList.add('hidden');
    searchInput.focus();
}
</script>

<?php include BASE_PATH . '/includes/footer.php';
