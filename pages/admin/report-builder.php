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
$page_title = t('Create Client Report');
$allowed_report_languages = ['en', 'cs', 'de', 'it', 'es'];
$allowed_group_by = ['none', 'day', 'task'];
$allowed_rounding = [0, 15, 30, 60];

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
    $theme_color = trim((string) ($_POST['theme_color'] ?? ''));
    if ($theme_color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $theme_color)) {
        $theme_color = '';
    }

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
        'group_by' => $group_by,
        'rounding_minutes' => $rounding_minutes,
        'theme_color' => $theme_color !== '' ? $theme_color : null,
        'hide_branding' => isset($_POST['hide_branding']) ? 1 : 0,
        'is_draft' => isset($_POST['save_as_draft']) ? 1 : 0
    ];

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
    'hide_branding' => $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['hide_branding']) : false,
];
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
                <h1 class="text-3xl font-bold" style="color: var(--text-primary);"><?php echo e(t('Create Client Report')); ?></h1>
                <p class="mt-1 text-sm" style="color: var(--text-muted);">
                    <?php echo e(t('Generate a professional time tracking report for your clients')); ?>
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

        <!-- Step 1: Basic Information -->
        <div class="card card-body">
            <h2 class="text-xl font-semibold mb-4 flex items-center" style="color: var(--text-primary);">
                <span class="bg-blue-50 dark:bg-blue-900/200 text-white rounded-full w-8 h-8 flex items-center justify-center text-sm font-bold mr-3">1</span>
                <?php echo e(t('Basic Information')); ?>
            </h2>

            <div class="space-y-4">
                <!-- Organization Selector -->
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);">
                        <?php echo e(t('Client / Organization')); ?> <span class="text-red-500">*</span>
                    </label>
                    <select name="organization_id" required size="8" class="form-input">
                        <option value=""><?php echo e(t('Select organization...')); ?></option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo $org['id']; ?>" <?php echo ((int) $form_values['organization_id'] === (int) $org['id']) ? 'selected' : ''; ?>>
                                <?php echo e($org['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-xs" style="color: var(--text-muted);"><?php echo e(t('Click to select a client')); ?></p>
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

        <!-- Form Actions -->
        <div class="flex items-center justify-between">
            <button type="submit" name="save_as_draft" value="1"
                    class="btn btn-secondary">
                <?php echo get_icon('save', 'w-4 h-4 mr-2 inline-block'); ?><?php echo e(t('Save as Draft')); ?>
            </button>

            <button type="submit"
                    class="btn btn-primary px-8 py-3 font-bold text-lg shadow-lg">
                <?php echo get_icon('send', 'w-5 h-5 mr-2 inline-block'); ?><?php echo e(t('Generate Report')); ?>
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

<?php include BASE_PATH . '/includes/footer.php'; 
