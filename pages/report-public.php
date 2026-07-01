<?php
/**
 * Public Report View - Client-Facing Time Report
 * Accessible via shareable link token
 */

// Increase timeout for data-heavy reports
set_time_limit(60);

// Enable output buffering to prevent partial output
ob_start();

// Get report share token parameter
$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . e(t('Error')) . '</title></head><body style="font-family:sans-serif;max-width:600px;margin:100px auto;text-align:center;"><h1 style="color:#dc2626;">' . e(t('Invalid report token')) . '</h1></body></html>');
}

// Fetch report template
$template = get_report_template_by_public_token($token);

if (!$template) {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body style="font-family:sans-serif;max-width:600px;margin:100px auto;text-align:center;"><h1 style="color:#dc2626;">' . e(t('Report not found or access denied')) . '</h1></body></html>');
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
        http_response_code(410);
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . e(t('Report Expired')) . '</title></head><body><div style="font-family: sans-serif; max-width: 600px; margin: 100px auto; text-align: center;">
            <h1 style="color: #dc2626; font-size: 24px; margin-bottom: 16px;">' . e(t('Report Expired')) . '</h1>
            <p style="color: #6b7280; font-size: 16px;">' . e(t('This report link has expired and is no longer accessible.')) . '</p>
            <p style="color: #9ca3af; font-size: 14px; margin-top: 24px;">' . e(t('Expired on')) . ': ' . e($expired_on_label) . '</p>
        </div></body></html>');
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
$theme_color = $template['theme_color'] ?: $template['organization_theme_color'] ?: '#3B82F6';

// Helper function to darken a hex color
function darken_color($hex, $percent = 20)
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) == 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $r = max(0, $r - ($r * $percent / 100));
    $g = max(0, $g - ($g * $percent / 100));
    $b = max(0, $b - ($b * $percent / 100));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
$theme_color_dark = darken_color($theme_color, 25);

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
?>
<!DOCTYPE html>
<html lang="<?php echo $template['report_language'] ?? 'en'; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($template['title']); ?> - <?php echo e($template['organization_name']); ?></title>

    <!-- Tailwind CSS -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

    <!-- Font Awesome (Removed) -->
    <!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"> -->

    <!-- Inter font is loaded via theme.css @font-face -->

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            .page-break {
                page-break-before: always;
            }

            body {
                font-size: 10pt;
            }

            a[href]:after {
                content: none !important;
            }
        }

        .kpi-card {
            background: var(--surface-primary);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            color: var(--text-primary);
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .kpi-card:hover {
            border-color: var(--border-medium);
        }

        .kpi-card-alt-1 {
            background: var(--surface-primary);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            color: var(--text-primary);
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .kpi-card-alt-1:hover {
            border-color: var(--border-medium);
        }

        .kpi-card-alt-2 {
            background: var(--surface-primary);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            color: var(--text-primary);
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .kpi-card-alt-2:hover {
            border-color: var(--border-medium);
        }

        .kpi-card-alt-3 {
            background: var(--surface-primary);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            color: var(--text-primary);
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .kpi-card-alt-3:hover {
            border-color: var(--border-medium);
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        thead th {
            background-color: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
        }

        tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            font-size: 14px;
        }

        tbody tr:hover {
            background-color: #f9fafb;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }
    </style>
</head>

<body class="bg-gray-50">

    <!-- Report Container -->
    <div class="max-w-7xl mx-auto p-4 lg:p-8">

        <!-- Report Header -->
        <div class="card card-body mb-5">
            <div class="flex items-start justify-between">
                <!-- Left: Report Company Logo -->
                <div class="flex-shrink-0">
                    <?php if ($report_company_logo): ?>
                        <img src="<?php echo e(upload_url($report_company_logo)); ?>" alt="Company Logo" class="h-16 object-contain">
                    <?php else: ?>
                        <div class="text-2xl font-bold" style="color: <?php echo $theme_color; ?>;">
                            <?php echo e($report_company_name); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Center: Report Title -->
                <div class="flex-1 text-center px-4">
                    <h1 class="text-3xl lg:text-4xl font-bold text-gray-900"><?php echo e($template['title']); ?></h1>
                    <div class="mt-2 inline-flex items-center px-4 py-2 bg-blue-50 dark:bg-blue-900/20 rounded-full">
                        <?php echo get_icon('calendar-alt', 'text-blue-600 mr-2 inline-block'); ?>
                        <span class="text-sm font-medium text-blue-900">
                            <?php echo e($date_from_formatted); ?> - <?php echo e($date_to_formatted); ?>
                        </span>
                    </div>
                </div>

                <!-- Right: Client Logo -->
                <div class="flex-shrink-0">
                    <?php if ($template['organization_logo']): ?>
                        <img src="<?php echo e(upload_url($template['organization_logo'])); ?>"
                            alt="<?php echo e($template['organization_name']); ?>" class="h-16 object-contain">
                    <?php else: ?>
                        <div class="text-lg font-semibold text-gray-600 text-right">
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
                    <span
                        style="color: <?php echo $theme_color; ?>;"><?php echo get_icon('file-alt', 'mr-2 inline-block'); ?></span>
                    <?php echo e(t('Executive Summary')); ?>
                </h2>
                <div class="prose max-w-none text-gray-700 leading-relaxed">
                    <?php echo nl2br(e($template['executive_summary'])); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-5">
            <!-- Total Time -->
            <div class="kpi-card fd-rounded-card p-6">
                <div class="flex items-center justify-between mb-2">
                    <?php echo get_icon('clock', 'text-3xl opacity-80 inline-block'); ?>
                    <span class="text-xs font-semibold uppercase tracking-wide"><?php echo e(t('Total Time')); ?></span>
                </div>
                <div class="text-4xl font-bold"><?php echo e(format_duration_minutes((int) ($kpis['total_minutes'] ?? 0))); ?></div>
            </div>

            <!-- Total Tasks -->
            <div class="kpi-card-alt-1 fd-rounded-card p-6">
                <div class="flex items-center justify-between mb-2">
                    <?php echo get_icon('tasks', 'text-3xl opacity-80 inline-block'); ?>
                    <span class="text-xs font-semibold uppercase tracking-wide"><?php echo e(t('Tasks')); ?></span>
                </div>
                <div class="text-4xl font-bold"><?php echo $kpis['total_tasks']; ?></div>
            </div>

            <!-- Total Amount (if enabled) -->
            <?php if ($template['show_financials']): ?>
                <div class="kpi-card-alt-2 fd-rounded-card p-6">
                    <div class="flex items-center justify-between mb-2">
                        <?php echo get_icon('coins', 'text-3xl opacity-80 inline-block'); ?>
                        <span
                            class="text-xs font-semibold uppercase tracking-wide"><?php echo e(t('Total Amount')); ?></span>
                    </div>
                    <div class="text-4xl font-bold"><?php echo format_money($kpis['total_cost']); ?></div>
                </div>
            <?php endif; ?>

            <!-- Team Members (if enabled) -->
            <?php if ($template['show_team_attribution']): ?>
                <div class="kpi-card-alt-3 fd-rounded-card p-6">
                    <div class="flex items-center justify-between mb-2">
                        <?php echo get_icon('users', 'text-3xl opacity-80 inline-block'); ?>
                        <span
                            class="text-xs font-semibold uppercase tracking-wide"><?php echo e(t('Team Members')); ?></span>
                    </div>
                    <div class="text-4xl font-bold"><?php echo $kpis['team_member_count']; ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Time Distribution Chart -->
        <div class="card card-body mb-5">
            <h2 class="text-xl font-bold text-gray-900 mb-5">
                <span
                    style="color: <?php echo $theme_color; ?>;"><?php echo get_icon('chart-bar', 'mr-2 inline-block'); ?></span>
                <?php echo e(t('Time Distribution')); ?>
            </h2>
            <div style="height: 300px;">
                <canvas id="timeChart"></canvas>
            </div>
        </div>

        <!-- Detailed Time Log -->
        <div class="card card-body mb-5">
            <h2 class="text-xl font-bold text-gray-900 mb-5">
                <span
                    style="color: <?php echo $theme_color; ?>;"><?php echo get_icon('list', 'mr-2 inline-block'); ?></span>
                <?php echo e(t('Detailed Time Log')); ?>
            </h2>

            <?php if (count($ticket_detail_rows) > 0): ?>
                <div class="report-ticket-list">
                    <?php foreach ($ticket_detail_rows as $ticket): ?>
                        <?php $detail_id = 'report-ticket-' . (int) $ticket['id']; ?>
                        <div class="report-ticket-card">
                            <button type="button"
                                class="report-ticket-summary"
                                data-report-ticket-row
                                aria-expanded="false"
                                aria-controls="<?php echo e($detail_id); ?>"
                                onclick="toggleReportTicket('<?php echo e($detail_id); ?>', this)">
                                <span class="report-ticket-summary__main">
                                    <span class="report-ticket-summary__icon" aria-hidden="true"><?php echo get_icon('chevron-right'); ?></span>
                                    <span>
                                        <span class="report-ticket-summary__title"><?php echo e($ticket['title']); ?></span>
                                        <span class="report-ticket-summary__meta">
                                            <?php echo e($ticket['code']); ?> · <?php echo e((string) $ticket['entries_count']); ?> <?php echo e(t('work records')); ?>
                                        </span>
                                    </span>
                                </span>
                                <span class="report-ticket-summary__totals">
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
                        backgroundColor: chartData.datasets[0].backgroundColor + '80', // 50% opacity
                        borderColor: chartData.datasets[0].borderColor,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return context.parsed.y + ' <?php echo e(t('Hours')); ?>';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: '<?php echo e(t('Hours')); ?>'
                            }
                        },
                        x: {
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
