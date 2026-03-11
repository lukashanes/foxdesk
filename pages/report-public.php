<?php
/**
 * Public Report View - Client-Facing Time Report
 * Accessible via shareable link with UUID token
 */

// Increase timeout for data-heavy reports
set_time_limit(60);

// Enable output buffering to prevent partial output
ob_start();

// This is a public page - no authentication required
$page_title = t('Time Report');

// Get report UUID from token parameter
$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body style="font-family:sans-serif;max-width:600px;margin:100px auto;text-align:center;"><h1 style="color:#dc2626;">Invalid report token</h1></body></html>');
}

// Fetch report template
$template = get_report_template_by_uuid($token);

if (!$template) {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body style="font-family:sans-serif;max-width:600px;margin:100px auto;text-align:center;"><h1 style="color:#dc2626;">' . e(t('Report not found or access denied')) . '</h1></body></html>');
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

// Set language for this report (save original and override temporarily)
$_report_original_lang = $_SESSION['lang'] ?? null;
$_report_original_override = $_SESSION['lang_override'] ?? null;
if (!empty($template['report_language'])) {
    $_SESSION['lang'] = $template['report_language'];
    $_SESSION['lang_override'] = true;
}

// Generate fresh data (no caching per requirements)
$time_entries = get_report_time_entries($template);
$kpis = calculate_report_kpis($time_entries, $template);
$chart_data = generate_report_chart_data($time_entries, $template);

// Group entries if configured
$display_entries = group_report_entries($time_entries, $template['group_by'], $template);

// Get branding settings
$report_company_logo = get_setting('report_company_logo', '');
$report_company_name = get_setting('report_company_name', APP_NAME);
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
            background: linear-gradient(135deg,
                    <?php echo $theme_color; ?>
                    0%,
                    <?php echo $theme_color_dark; ?>
                    100%);
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.2);
            transform: translateY(0);
            transition: all 0.3s ease;
        }

        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 40px -5px rgba(0, 0, 0, 0.15);
        }

        .kpi-card-alt-1 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 10px 30px -5px rgba(102, 126, 234, 0.3);
            transform: translateY(0);
            transition: all 0.3s ease;
        }

        .kpi-card-alt-1:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 40px -5px rgba(102, 126, 234, 0.4);
        }

        .kpi-card-alt-2 {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            box-shadow: 0 10px 30px -5px rgba(99, 102, 241, 0.3);
            transform: translateY(0);
            transition: all 0.3s ease;
        }

        .kpi-card-alt-2:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 40px -5px rgba(99, 102, 241, 0.4);
        }

        .kpi-card-alt-3 {
            background: linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%);
            box-shadow: 0 10px 30px -5px rgba(59, 130, 246, 0.3);
            transform: translateY(0);
            transition: all 0.3s ease;
        }

        .kpi-card-alt-3:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 40px -5px rgba(59, 130, 246, 0.4);
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
            <div class="kpi-card text-white rounded-lg p-6 shadow-lg">
                <div class="flex items-center justify-between mb-2">
                    <?php echo get_icon('clock', 'text-3xl opacity-80 inline-block'); ?>
                    <span class="text-xs font-semibold uppercase tracking-wide"><?php echo e(t('Total Time')); ?></span>
                </div>
                <div class="text-4xl font-bold"><?php echo e(format_duration_minutes((int) ($kpis['total_minutes'] ?? 0))); ?></div>
            </div>

            <!-- Total Tasks -->
            <div class="kpi-card-alt-1 text-white rounded-lg p-6 shadow-lg">
                <div class="flex items-center justify-between mb-2">
                    <?php echo get_icon('tasks', 'text-3xl opacity-80 inline-block'); ?>
                    <span class="text-xs font-semibold uppercase tracking-wide"><?php echo e(t('Tasks')); ?></span>
                </div>
                <div class="text-4xl font-bold"><?php echo $kpis['total_tasks']; ?></div>
            </div>

            <!-- Total Amount (if enabled) -->
            <?php if ($template['show_financials']): ?>
                <div class="kpi-card-alt-2 text-white rounded-lg p-6 shadow-lg">
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
                <div class="kpi-card-alt-3 text-white rounded-lg p-6 shadow-lg">
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

            <?php if ($template['group_by'] !== 'none' && count($display_entries) > 0): ?>
                <!-- Grouped View -->
                <div class="space-y-4">
                    <?php foreach ($display_entries as $group): ?>
                        <div class="border border-gray-200 rounded-lg overflow-hidden">
                            <!-- Group Header (Collapsible) -->
                            <div class="bg-gray-50 px-4 py-3 cursor-pointer hover:bg-gray-100 transition"
                                onclick="toggleGroup('group-<?php echo e($group['group_key']); ?>')">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <span id="icon-<?php echo e($group['group_key']); ?>"
                                            class="group-icon mr-3 text-gray-400 transition-transform inline-block">
                                            <?php echo get_icon('chevron-right'); ?>
                                        </span>
                                        <div>
                                            <div class="font-semibold text-gray-900"><?php echo e($group['group_label']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500"><?php echo count($group['entries']); ?>
                                                <?php echo e(t('entries')); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-bold text-gray-900">
                                            <?php echo format_duration_minutes($group['total_minutes']); ?>
                                        </div>
                                        <?php if ($template['show_financials']): ?>
                                            <div class="text-sm text-gray-600"><?php echo format_money($group['total_cost']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Group Details (Expandable) -->
                            <div id="group-<?php echo e($group['group_key']); ?>" class="hidden bg-white">
                                <table class="w-full">
                                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-600">
                                        <tr>
                                            <th class="px-4 py-3 text-left"><?php echo e(t('Date')); ?></th>
                                            <th class="px-4 py-3 text-left"><?php echo e(t('Task')); ?></th>
                                            <th class="px-4 py-3 text-left"><?php echo e(t('Time Range')); ?></th>
                                            <th class="px-4 py-3 text-right"><?php echo e(t('Duration')); ?></th>
                                            <?php if ($template['show_team_attribution']): ?>
                                                <th class="px-4 py-3 text-left"><?php echo e(t('Member')); ?></th>
                                            <?php endif; ?>
                                            <?php if ($template['show_financials'] && $template['show_cost_breakdown']): ?>
                                                <th class="px-4 py-3 text-right"><?php echo e(t('Cost')); ?></th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($group['entries'] as $entry): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-3 text-sm text-gray-600">
                                                    <?php echo e(function_exists('format_date') ? format_date($entry['entry_date'], 'd.m.Y') : date('d.m.Y', strtotime($entry['entry_date']))); ?>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo e($entry['ticket_title']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">#<?php echo $entry['ticket_id']; ?></div>
                                                    <?php $entry_tags = $extract_report_tags($entry['ticket_tags'] ?? ''); ?>
                                                    <?php if (!empty($entry_tags)): ?>
                                                        <div class="mt-1 flex flex-wrap gap-1">
                                                            <?php foreach ($entry_tags as $tag): ?>
                                                                <span
                                                                    class="badge-inline bg-indigo-50 text-indigo-700">#<?php echo e($tag); ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-sm text-gray-600 font-mono">
                                                    <?php echo format_time_range($entry); ?>
                                                </td>
                                                <td class="px-4 py-3 text-sm text-right font-medium text-gray-900">
                                                    <?php echo format_duration_minutes($entry['duration_minutes']); ?>
                                                </td>
                                                <?php if ($template['show_team_attribution']): ?>
                                                    <td class="px-4 py-3 text-sm text-gray-600">
                                                        <?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?>
                                                    </td>
                                                <?php endif; ?>
                                                <?php if ($template['show_financials'] && $template['show_cost_breakdown']): ?>
                                                    <td class="px-4 py-3 text-sm text-right font-medium text-gray-900">
                                                        <?php
                                                        $rate = $entry['billable_rate'] ?: 0;
                                                        $cost = ($entry['duration_minutes'] / 60) * $rate;
                                                        echo format_money($cost);
                                                        ?>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <!-- Flat View (No Grouping) -->
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-600">
                            <tr>
                                <th class="px-4 py-3 text-left"><?php echo e(t('Date')); ?></th>
                                <th class="px-4 py-3 text-left"><?php echo e(t('Project / Task')); ?></th>
                                <th class="px-4 py-3 text-left"><?php echo e(t('Time Range')); ?></th>
                                <th class="px-4 py-3 text-right"><?php echo e(t('Duration')); ?></th>
                                <?php if ($template['show_team_attribution']): ?>
                                    <th class="px-4 py-3 text-left"><?php echo e(t('Member')); ?></th>
                                <?php endif; ?>
                                <?php if ($template['show_financials'] && $template['show_cost_breakdown']): ?>
                                    <th class="px-4 py-3 text-right"><?php echo e(t('Cost')); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($display_entries as $entry): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <?php echo e(function_exists('format_date') ? format_date($entry['entry_date'], 'd.m.Y') : date('d.m.Y', strtotime($entry['entry_date']))); ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm font-medium text-gray-900"><?php echo e($entry['ticket_title']); ?>
                                        </div>
                                        <?php if (!empty($entry['ticket_type'])): ?>
                                            <span class="text-xs text-gray-500"><?php echo e($entry['ticket_type']); ?></span>
                                        <?php endif; ?>
                                        <?php $entry_tags = $extract_report_tags($entry['ticket_tags'] ?? ''); ?>
                                        <?php if (!empty($entry_tags)): ?>
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                <?php foreach ($entry_tags as $tag): ?>
                                                    <span
                                                        class="badge-inline bg-indigo-50 text-indigo-700">#<?php echo e($tag); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600 font-mono">
                                        <?php echo format_time_range($entry); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-right font-medium text-gray-900">
                                        <?php echo format_duration_minutes($entry['duration_minutes']); ?>
                                    </td>
                                    <?php if ($template['show_team_attribution']): ?>
                                        <td class="px-4 py-3 text-sm text-gray-600">
                                            <?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?>
                                        </td>
                                    <?php endif; ?>
                                    <?php if ($template['show_financials'] && $template['show_cost_breakdown']): ?>
                                        <td class="px-4 py-3 text-sm text-right font-medium text-gray-900">
                                            <?php
                                            $rate = $entry['cost_rate'] ?: $entry['user_cost_rate'] ?: 0;
                                            $cost = ($entry['duration_minutes'] / 60) * $rate;
                                            echo format_money($cost);
                                            ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (count($display_entries) === 0): ?>
                <div class="text-center py-12 text-gray-500">
                    <?php echo get_icon('inbox', 'text-4xl mb-4 opacity-50 inline-block'); ?>
                    <p><?php echo e(t('No time entries found for this period')); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="flex justify-center space-x-4 mb-5 no-print">
            <button onclick="window.print()"
                class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition shadow-lg">
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

        // Toggle group expand/collapse
        function toggleGroup(groupId) {
            const group = document.getElementById(groupId);
            const icon = document.getElementById('icon-' + groupId.replace('group-', ''));

            if (group.classList.contains('hidden')) {
                group.classList.remove('hidden');
                icon.style.transform = 'rotate(90deg)';
            } else {
                group.classList.add('hidden');
                icon.style.transform = 'rotate(0deg)';
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

