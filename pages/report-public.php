<?php
/**
 * Public Report View - Client-Facing Time Report
 * Accessible via shareable link token
 */

// Increase timeout for data-heavy reports
set_time_limit(60);

// Enable output buffering to prevent partial output
ob_start();

function render_public_report_error_page(string $title, string $message, string $meta = '', int $status = 400): void
{
    http_response_code($status);
    $theme_version = (defined('APP_VERSION') ? (string) APP_VERSION : (string) time())
        . '-' . (string) (@filemtime(BASE_PATH . '/assets/css/theme.min.css') ?: '0');
    echo '<!DOCTYPE html><html lang="' . e(get_app_language()) . '" dir="' . e(get_app_direction()) . '"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<meta name="robots" content="noindex, nofollow">';
    echo '<title>' . e($title) . '</title>';
    echo '<link href="tailwind.min.css?v=' . e($theme_version) . '" rel="stylesheet">';
    echo '<link href="assets/css/theme.min.css?v=' . e($theme_version) . '" rel="stylesheet">';
    echo '</head><body class="report-public-error-page">';
    echo '<main class="report-public-error-card">';
    echo '<h1>' . e($title) . '</h1>';
    echo '<p>' . e($message) . '</p>';
    if ($meta !== '') {
        echo '<p class="report-public-error-meta">' . e($meta) . '</p>';
    }
    echo '</main></body></html>';
    exit;
}

// Get report share token parameter
$token = $_GET['token'] ?? '';

if (empty($token)) {
    render_public_report_error_page(t('Error'), t('Invalid report token'), '', 400);
}

// Fetch report template
$template = get_report_template_by_public_token($token);

if (!$template) {
    render_public_report_error_page(t('Error'), t('Report not found or access denied'), '', 404);
}

// Set language for this report (save original and override temporarily)
$_report_original_lang = $_SESSION['lang'] ?? null;
$_report_original_override = $_SESSION['lang_override'] ?? null;
if (!empty($template['report_language'])) {
    $_SESSION['lang'] = $template['report_language'];
    $_SESSION['lang_override'] = true;
}

// Check if report has expired
if (!empty($template['expires_at'])) {
    $expires_timestamp = strtotime($template['expires_at']);
    if ($expires_timestamp !== false && $expires_timestamp < time()) {
        $expired_on_label = function_exists('format_date')
            ? format_date(date('Y-m-d H:i:s', $expires_timestamp), 'd.m.Y H:i')
            : date('d.m.Y H:i', $expires_timestamp);
        render_public_report_error_page(
            t('Report Expired'),
            t('This report link has expired and is no longer accessible.'),
            t('Expired on') . ': ' . $expired_on_label,
            410
        );
    }
}

// This is a public page - no authentication required
$page_title = t('Time Report');

// Generate fresh data (no caching per requirements)
$time_entries = get_report_time_entries($template);
$kpis = calculate_report_kpis($time_entries, $template);
$chart_data = generate_report_chart_data($time_entries, $template);
$ticket_detail_model = report_ticket_detail_model($time_entries, $template, true);
$ticket_detail_rows = $ticket_detail_model['tickets'];

// Get branding settings
$report_company_logo = get_setting('report_company_logo', '');
$report_company_name = get_setting('report_company_name', defined('APP_NAME') ? APP_NAME : 'FoxDesk');
$show_branding = !$template['hide_branding'] && get_setting('report_show_branding', '1') == '1';

// Theme color
// Format dates
$date_from_formatted = function_exists('format_date')
    ? format_date($template['date_from'], 'd.m.Y')
    : date('d.m.Y', strtotime($template['date_from']));
$date_to_formatted = function_exists('format_date')
    ? format_date($template['date_to'], 'd.m.Y')
    : date('d.m.Y', strtotime($template['date_to']));
$extract_report_tags = static function ($value) {
    if ($value === null || $value === '') {
        return [];
    }
    if (function_exists('get_ticket_tags_array')) {
        return array_slice(get_ticket_tags_array($value), 0, 4);
    }
    $parts = array_filter(array_map('trim', explode(',', (string) $value)));
    return array_slice(array_values($parts), 0, 4);
};
$report_public_theme_version = (defined('APP_VERSION') ? (string) APP_VERSION : '1')
    . '-' . (string) (@filemtime(BASE_PATH . '/assets/css/theme.min.css') ?: '0');
$report_public_tailwind_version = (defined('APP_VERSION') ? (string) APP_VERSION : '1')
    . '-' . (string) (@filemtime(BASE_PATH . '/tailwind.min.css') ?: '0');
?>
<!DOCTYPE html>
<html lang="<?php echo e($template['report_language'] ?? 'en'); ?>" dir="<?php echo e(get_app_direction($template['report_language'] ?? 'en')); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo e($template['title']); ?> - <?php echo e($template['organization_name']); ?></title>

    <link href="tailwind.min.css?v=<?php echo e($report_public_tailwind_version); ?>" rel="stylesheet">
    <link href="assets/css/theme.min.css?v=<?php echo e($report_public_theme_version); ?>" rel="stylesheet">
    <link href="index.php?page=report-theme&amp;token=<?php echo e(rawurlencode($token)); ?>&amp;v=<?php echo e($report_public_theme_version); ?>" rel="stylesheet">

    <!-- Chart.js -->
    <script src="assets/vendor/chartjs/4.4.0/chart.umd.js?v=<?php echo e((string) APP_VERSION); ?>"></script>
</head>

<body class="report-public-page bg-gray-50">

    <!-- Report Container -->
    <div class="report-public-container max-w-7xl mx-auto p-4 lg:p-8">

        <!-- Report Header -->
        <div class="card card-body report-public-header-card mb-5">
            <div class="report-public-header">
                <!-- Left: Report Company Logo -->
                <div class="report-public-header__brand">
                    <?php if ($report_company_logo): ?>
                        <img src="<?php echo e(upload_url($report_company_logo)); ?>" alt="Company Logo" class="report-public-header__logo">
                    <?php else: ?>
                        <div class="report-public-header__company report-theme-text font-bold">
                            <?php echo e($report_company_name); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Center: Report Title -->
                <div class="report-public-header__title">
                    <h1 class="report-public-header__heading font-bold text-gray-900"><?php echo e($template['title']); ?></h1>
                    <div class="report-public-header__period fd-rounded-pill">
                        <span class="report-public-header__period-icon" aria-hidden="true"><?php echo get_icon('calendar-alt'); ?></span>
                        <span class="report-public-header__period-label font-medium">
                            <?php echo e($date_from_formatted); ?> - <?php echo e($date_to_formatted); ?>
                        </span>
                    </div>
                </div>

                <!-- Right: Client Logo -->
                <div class="report-public-header__client">
                    <?php if ($template['organization_logo']): ?>
                        <img src="<?php echo e(upload_url($template['organization_logo'])); ?>"
                            alt="<?php echo e($template['organization_name']); ?>" class="report-public-header__logo">
                    <?php else: ?>
                        <div class="report-public-header__client-name font-semibold">
                            <?php echo e($template['organization_name']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Executive Summary -->
        <?php if (!empty($template['executive_summary'])): ?>
            <div class="card card-body mb-5">
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    <span class="report-theme-text"><?php echo get_icon('file-alt', 'mr-2 inline-block'); ?></span>
                    <?php echo e(t('Executive Summary')); ?>
                </h2>
                <div class="prose max-w-none text-gray-700 leading-relaxed">
                    <?php echo nl2br(e($template['executive_summary'])); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <?php if ((int) ($template['rounding_minutes'] ?? 0) > 1): ?>
            <p class="text-sm text-theme-secondary mb-3"><?php echo e(t('Billable time is rounded up per billable item by the report rounding setting ({minutes} min). It can be higher than tracked time.', ['minutes'=>(int)$template['rounding_minutes']])); ?></p>
        <?php endif; ?>
        <div class="report-public-kpis mb-5">
            <!-- Total Time -->
            <div class="report-public-kpi kpi-card fd-rounded-card">
                <span class="report-public-kpi__icon" aria-hidden="true"><?php echo get_icon('clock'); ?></span>
                <span class="report-public-kpi__label"><?php echo e(t('Total Time')); ?></span>
                <div class="report-public-kpi__value"><?php echo e(format_duration_minutes((int) ($kpis['total_minutes'] ?? 0))); ?></div>
            </div>

            <!-- Billable Time -->
            <div class="report-public-kpi kpi-card-alt-1 fd-rounded-card">
                <span class="report-public-kpi__icon" aria-hidden="true"><?php echo get_icon('credit-card'); ?></span>
                <span class="report-public-kpi__label"><?php echo e(t('Billable time')); ?></span>
                <div class="report-public-kpi__value"><?php echo e(format_duration_minutes((int) ($kpis['billable_minutes'] ?? 0))); ?></div>
            </div>

            <!-- Total Tasks -->
            <div class="report-public-kpi kpi-card-alt-2 fd-rounded-card">
                <span class="report-public-kpi__icon" aria-hidden="true"><?php echo get_icon('tasks'); ?></span>
                <span class="report-public-kpi__label"><?php echo e(t('Tasks')); ?></span>
                <div class="report-public-kpi__value"><?php echo $kpis['total_tasks']; ?></div>
            </div>

            <!-- Total Amount (if enabled) -->
            <?php if ($template['show_financials']): ?>
                <div class="report-public-kpi kpi-card-alt-3 fd-rounded-card">
                    <span class="report-public-kpi__icon" aria-hidden="true"><?php echo get_icon('coins'); ?></span>
                    <span class="report-public-kpi__label"><?php echo e(t('Total Amount')); ?></span>
                    <div class="report-public-kpi__value"><?php echo format_money($kpis['total_cost']); ?></div>
                </div>
            <?php endif; ?>

            <!-- Team Members (if enabled) -->
            <?php if ($template['show_team_attribution']): ?>
                <div class="report-public-kpi kpi-card-alt-1 fd-rounded-card">
                    <span class="report-public-kpi__icon" aria-hidden="true"><?php echo get_icon('users'); ?></span>
                    <span class="report-public-kpi__label"><?php echo e(t('Team Members')); ?></span>
                    <div class="report-public-kpi__value"><?php echo $kpis['team_member_count']; ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Time Distribution Chart -->
        <div class="card card-body mb-5">
            <h2 class="text-xl font-bold text-gray-900 mb-5">
                <span class="report-theme-text"><?php echo get_icon('chart-bar', 'mr-2 inline-block'); ?></span>
                <?php echo e(t('Time Distribution')); ?>
            </h2>
            <div class="report-chart-shell">
                <canvas id="timeChart"></canvas>
            </div>
        </div>

        <!-- Detailed Time Log -->
        <div class="card card-body mb-5">
            <h2 class="text-xl font-bold text-gray-900 mb-5">
                <span class="report-theme-text"><?php echo get_icon('list', 'mr-2 inline-block'); ?></span>
                <?php echo e(t('Detailed Time Log')); ?>
            </h2>

            <?php if (count($ticket_detail_rows) > 0): ?>
                <div class="report-ticket-list">
                    <?php foreach ($ticket_detail_rows as $ticket): ?>
                        <?php $detail_id = 'report-ticket-' . (int) $ticket['id']; ?>
                        <div class="report-ticket-card fd-report-ticket-card">
                            <button type="button"
                                class="report-ticket-summary fd-report-ticket-summary"
                                data-report-ticket-row
                                aria-expanded="false"
                                aria-controls="<?php echo e($detail_id); ?>"
                                onclick="toggleReportTicket('<?php echo e($detail_id); ?>', this)">
                                <span class="report-ticket-summary__main fd-report-ticket-main">
                                    <span class="report-ticket-summary__icon fd-report-ticket-icon" aria-hidden="true"><?php echo get_icon('chevron-right'); ?></span>
                                    <span class="fd-report-ticket-copy">
                                        <span class="report-ticket-summary__title fd-report-ticket-title"><bdi dir="auto"><?php echo e($ticket['title']); ?></bdi></span>
                                        <span class="report-ticket-summary__meta fd-report-ticket-meta">
                                            <?php echo e($ticket['code']); ?> · <?php echo e((string) $ticket['entries_count']); ?> <?php echo e(t('work records')); ?>
                                        </span>
                                    </span>
                                </span>
                                <span class="report-ticket-summary__totals fd-report-ticket-totals">
                                    <strong><?php echo e(format_duration_minutes($ticket['minutes'])); ?></strong>
                                    <?php if ($template['show_financials']): ?>
                                        <span><?php echo e(format_money($ticket['amount'])); ?></span>
                                    <?php endif; ?>
                                </span>
                            </button>

                            <div id="<?php echo e($detail_id); ?>" class="report-ticket-details hidden" data-report-ticket-details>
                                <div class="overflow-x-auto">
                                    <table class="w-full">
                                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-600">
                                            <tr>
                                                <th class="px-4 py-3 text-left"><?php echo e(t('Date')); ?></th>
                                                <th class="px-4 py-3 text-left"><?php echo e(t('Work details')); ?></th>
                                                <th class="px-4 py-3 text-left"><?php echo e(t('Time Range')); ?></th>
                                                <th class="px-4 py-3 text-right"><?php echo e(t('Duration')); ?></th>
                                                <?php if ($template['show_team_attribution']): ?>
                                                    <th class="px-4 py-3 text-left"><?php echo e(t('Member')); ?></th>
                                                <?php endif; ?>
                                                <?php if ($template['show_financials'] && $template['show_cost_breakdown']): ?>
                                                    <th class="px-4 py-3 text-right"><?php echo e(t('Amount')); ?></th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <?php foreach ($ticket['entries'] as $entry): ?>
                                                <tr data-report-comment-row>
                                                    <td class="px-4 py-3 text-sm text-gray-600">
                                                        <?php echo e($entry['date'] !== '' && function_exists('format_date') ? format_date($entry['date'], 'd.m.Y') : $entry['date']); ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <?php if ($entry['comment_html'] !== ''): ?>
                                                            <div class="report-comment-body">
                                                                <?php echo safe_html($entry['comment_html']); ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="text-sm text-gray-700"><?php echo e($entry['summary']); ?></div>
                                                            <div class="text-xs text-gray-500"><?php echo e(t('No public comment was added for this time entry.')); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-sm text-gray-600 font-mono">
                                                        <?php echo e($entry['time_range']); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-sm text-right font-medium text-gray-900">
                                                        <?php echo e(format_duration_minutes($entry['duration_minutes'])); ?>
                                                    </td>
                                                    <?php if ($template['show_team_attribution']): ?>
                                                        <td class="px-4 py-3 text-sm text-gray-600">
                                                            <?php echo e($entry['agent_name']); ?>
                                                        </td>
                                                    <?php endif; ?>
                                                    <?php if ($template['show_financials'] && $template['show_cost_breakdown']): ?>
                                                        <td class="px-4 py-3 text-sm text-right font-medium text-gray-900">
                                                            <?php echo e(format_money($entry['amount'])); ?>
                                                        </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (count($ticket_detail_rows) === 0): ?>
                <div class="text-center py-12 text-gray-500">
                    <?php echo get_icon('inbox', 'text-4xl mb-4 opacity-50 inline-block'); ?>
                    <p><?php echo e(t('No time entries found for this period')); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="flex justify-center space-x-4 mb-5 no-print">
            <button onclick="window.print()" class="fd-button fd-button--primary">
                <?php echo get_icon('print', 'mr-2 inline-block'); ?><?php echo e(t('Print / Save as PDF')); ?>
            </button>
        </div>

        <!-- Footer Branding -->
        <?php if ($show_branding): ?>
            <div class="text-center py-6 text-sm text-gray-500 border-t border-gray-200">
                <?php echo e(t('Generated by')); ?> <strong><?php echo e($report_company_name); ?></strong>
                <span class="mx-2">—</span>
                <a href="<?php echo APP_URL; ?>" class="text-blue-600 hover:underline"><?php echo e(APP_URL); ?></a>
            </div>
        <?php endif; ?>

    </div>

    <script>
        // Chart.js - Time Distribution
        document.addEventListener('DOMContentLoaded', function () {
            const chartElement = document.getElementById('timeChart');
            if (!chartElement) {
                console.error('Chart canvas element not found');
                return;
            }

            const ctx = chartElement.getContext('2d');
            const chartData = <?php echo json_encode($chart_data); ?>;

            if (!chartData || !chartData.labels || !chartData.datasets) {
                console.error('Invalid chart data:', chartData);
                return;
            }

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        label: chartData.datasets[0].label,
                        data: chartData.datasets[0].data,
                        backgroundColor: '#a9bfeb',
                        hoverBackgroundColor: '#8daadc',
                        borderWidth: 0,
                        borderRadius: 5,
                        maxBarThickness: 30
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 350 },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    const minutes = Math.round(context.parsed.y * 60);
                                    const locale = document.documentElement.lang || 'en';
                                    const hoursLabel = new Intl.NumberFormat(locale, { style: 'unit', unit: 'hour', unitDisplay: 'short' });
                                    const minutesLabel = new Intl.NumberFormat(locale, { style: 'unit', unit: 'minute', unitDisplay: 'short' });
                                    return (minutes >= 60 ? hoursLabel.format(Math.floor(minutes / 60)) + ' ' : '') + minutesLabel.format(minutes % 60);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            border: { display: false },
                            grid: { color: '#edf1f7', drawTicks: false },
                            ticks: { maxTicksLimit: 5, padding: 12, color: '#65748b' },
                            title: {
                                display: true,
                                text: '<?php echo e(t('Hours')); ?>'
                            }
                        },
                        x: {
                            border: { display: false },
                            grid: { display: false },
                            ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12, padding: 10, color: '#65748b' },
                            title: {
                                display: true,
                                text: '<?php echo e(t('Date')); ?>'
                            }
                        }
                    }
                }
            });
        });

        function toggleReportTicket(detailId, button) {
            const detail = document.getElementById(detailId);
            if (!detail) return;

            const isOpening = detail.classList.contains('hidden');
            detail.classList.toggle('hidden', !isOpening);
            if (button) {
                button.setAttribute('aria-expanded', isOpening ? 'true' : 'false');
            }
        }
    </script>

</body>

</html>
<?php
// Restore original session language
if ($_report_original_lang !== null) {
    $_SESSION['lang'] = $_report_original_lang;
} else {
    unset($_SESSION['lang']);
}
if ($_report_original_override !== null) {
    $_SESSION['lang_override'] = $_report_original_override;
} else {
    unset($_SESSION['lang_override']);
}
// Flush output buffer
ob_end_flush();
