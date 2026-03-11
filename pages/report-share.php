<?php
/**
 * Report Share Page (Public, read-only)
 */

$settings = get_settings();
$app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');

$token = trim($_GET['token'] ?? '');
$share = get_report_share_by_token($token);

function render_report_share_message($app_name, $title, $message)
{
    ?>
    <!DOCTYPE html>
    <html lang="<?php echo e(get_app_language()); ?>">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo e($title); ?> - <?php echo e($app_name); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
        <link href="theme.css" rel="stylesheet">
    </head>

    <body class="bg-gray-100 min-h-screen">
        <div class="max-w-3xl mx-auto px-4 py-10">
            <div class="card card-body text-center">
                <div class="text-lg font-semibold text-gray-800"><?php echo e($title); ?></div>
                <p class="text-sm text-gray-600 mt-2"><?php echo e($message); ?></p>
                <a href="<?php echo url('login'); ?>" class="inline-block mt-4 text-blue-600 hover:text-blue-700 text-sm">
                    <?php echo e(t('Sign in')); ?>
                </a>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
}

if (!$share || !is_report_share_active($share)) {
    render_report_share_message($app_name, t('Link not available'), t('This share link is invalid, expired, or revoked.'));
}

$organization = get_organization($share['organization_id']);
if (!$organization) {
    render_report_share_message($app_name, t('Company'), t('This ticket is no longer available.'));
}

$time_range = trim($_GET['time_range'] ?? 'this_month');
$from_date = trim($_GET['from_date'] ?? '');
$to_date = trim($_GET['to_date'] ?? '');
$range_data = get_time_range_bounds($time_range, $from_date, $to_date);
$time_range = $range_data['range'];
$range_start = $range_data['start'];
$range_end = $range_data['end'];

$sql = "SELECT tte.*, t.title as ticket_title
        FROM ticket_time_entries tte
        JOIN tickets t ON tte.ticket_id = t.id
        WHERE t.organization_id = ?";
$params = [$organization['id']];
if ($range_start && $range_end) {
    $sql .= " AND tte.started_at >= ? AND tte.started_at <= ?";
    $params[] = $range_start;
    $params[] = $range_end;
}
$sql .= " ORDER BY tte.started_at DESC, tte.id DESC";
$entries = db_fetch_all($sql, $params);

$total_minutes = 0;
$by_ticket = [];
foreach ($entries as &$entry) {
    $actual_minutes = (int) $entry['duration_minutes'];
    if (empty($entry['ended_at']) && !empty($entry['started_at'])) {
        $actual_minutes = max(0, (int) floor((time() - strtotime($entry['started_at'])) / 60));
    }
    $entry['actual_minutes'] = $actual_minutes;
    $total_minutes += $actual_minutes;

    $ticket_key = (string) $entry['ticket_id'];
    if (!isset($by_ticket[$ticket_key])) {
        $by_ticket[$ticket_key] = [
            'title' => $entry['ticket_title'],
            'minutes' => 0
        ];
    }
    $by_ticket[$ticket_key]['minutes'] += $actual_minutes;
}
unset($entry);

mark_report_share_accessed($share['id']);
$expires_label = !empty($share['expires_at']) ? format_date($share['expires_at']) : t('Never');
?>
<!DOCTYPE html>
<html lang="<?php echo e(get_app_language()); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('Time report')); ?> - <?php echo e($app_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="theme.css" rel="stylesheet">
</head>

<body class="bg-gray-100 min-h-screen">
    <header class="bg-white border-b">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 bg-blue-50 dark:bg-blue-900/200 rounded-lg flex items-center justify-center">
                    <span class="text-white font-bold"><?php echo strtoupper(substr($app_name, 0, 1)); ?></span>
                </div>
                <div>
                    <div class="text-lg font-semibold text-gray-800"><?php echo e($app_name); ?></div>
                    <div class="text-xs text-gray-500"><?php echo e(t('Time report')); ?></div>
                </div>
            </div>
            <a href="<?php echo url('login'); ?>"
                class="text-sm text-blue-600 hover:text-blue-700"><?php echo e(t('Sign in')); ?></a>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 py-6 space-y-5">
        <div class="card card-body">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="text-sm text-gray-500"><?php echo e($organization['name']); ?></div>
                    <div class="text-xs text-gray-500">
                        <?php echo e(t('Expires: {date}', ['date' => $expires_label])); ?>
                    </div>
                </div>
                <form method="get" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="page" value="report-share">
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1"><?php echo e(t('Time range')); ?></label>
                        <select name="time_range" id="report-share-range" class="form-select">
                            <option value="all" <?php echo $time_range === 'all' ? 'selected' : ''; ?>>
                                <?php echo e(t('All time')); ?>
                            </option>
                            <option value="yesterday" <?php echo $time_range === 'yesterday' ? 'selected' : ''; ?>>
                                <?php echo e(t('Yesterday')); ?>
                            </option>
                            <option value="this_week" <?php echo $time_range === 'this_week' ? 'selected' : ''; ?>>
                                <?php echo e(t('This week')); ?>
                            </option>
                            <option value="last_week" <?php echo $time_range === 'last_week' ? 'selected' : ''; ?>>
                                <?php echo e(t('Last week')); ?>
                            </option>
                            <option value="this_month" <?php echo $time_range === 'this_month' ? 'selected' : ''; ?>>
                                <?php echo e(t('This month')); ?>
                            </option>
                            <option value="last_month" <?php echo $time_range === 'last_month' ? 'selected' : ''; ?>>
                                <?php echo e(t('Last month')); ?>
                            </option>
                            <option value="this_year" <?php echo $time_range === 'this_year' ? 'selected' : ''; ?>>
                                <?php echo e(t('This year')); ?>
                            </option>
                            <option value="last_year" <?php echo $time_range === 'last_year' ? 'selected' : ''; ?>>
                                <?php echo e(t('Last year')); ?>
                            </option>
                            <option value="custom" <?php echo $time_range === 'custom' ? 'selected' : ''; ?>>
                                <?php echo e(t('Custom range')); ?>
                            </option>
                        </select>
                    </div>
                    <div id="report-share-custom"
                        class="flex items-end gap-3 <?php echo $time_range === 'custom' ? '' : 'hidden'; ?>">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1"><?php echo e(t('From date')); ?></label>
                            <input type="date" name="from_date" value="<?php echo e($from_date); ?>" class="form-input">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1"><?php echo e(t('To date')); ?></label>
                            <input type="date" name="to_date" value="<?php echo e($to_date); ?>" class="form-input">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm"><?php echo e(t('Apply')); ?></button>
                </form>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="card card-body">
                <div class="text-xs text-gray-500"><?php echo e(t('Total time')); ?></div>
                <div class="text-lg font-semibold"><?php echo e(format_duration_minutes($total_minutes)); ?></div>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="px-6 py-4 border-b">
                <h3 class="font-semibold text-gray-800"><?php echo e(t('Tickets')); ?></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('Ticket')); ?>
                            </th>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('Time')); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($by_ticket as $ticket): ?>
                            <tr>
                                <td class="px-6 py-3 text-sm text-gray-700"><?php echo e($ticket['title']); ?></td>
                                <td class="px-6 py-3 text-sm text-gray-600">
                                    <?php echo e(format_duration_minutes($ticket['minutes'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="px-6 py-4 border-b">
                <h3 class="font-semibold text-gray-800"><?php echo e(t('Detailed')); ?></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('Ticket')); ?>
                            </th>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('Time')); ?>
                            </th>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('Start time')); ?>
                            </th>
                            <th class="px-6 py-3 text-left th-label">
                                <?php echo e(t('End time')); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td class="px-6 py-3 text-sm text-gray-700"><?php echo e($entry['ticket_title']); ?></td>
                                <td class="px-6 py-3 text-sm text-gray-600">
                                    <?php echo e(format_duration_minutes($entry['actual_minutes'])); ?>
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-600">
                                    <?php echo e(format_date($entry['started_at'])); ?>
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-600">
                                    <?php echo e($entry['ended_at'] ? format_date($entry['ended_at']) : '-'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        const shareRangeSelect = document.getElementById('report-share-range');
        const shareCustomRange = document.getElementById('report-share-custom');
        if (shareRangeSelect && shareCustomRange) {
            const toggleShareRange = () => {
                shareCustomRange.classList.toggle('hidden', shareRangeSelect.value !== 'custom');
            };
            shareRangeSelect.addEventListener('change', toggleShareRange);
            toggleShareRange();
        }
    </script>
</body>

</html>
