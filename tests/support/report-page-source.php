<?php

function report_page_source_files(): array
{
    return [
        'pages/admin/reports.php',
        'includes/modules/reports/report-page-controller.php',
        'includes/modules/reports/report-page-view-model.php',
        'includes/modules/reports/report-page-render.php',
        'includes/modules/reports/views/page.php',
        'includes/modules/reports/views/filters.php',
        'includes/modules/reports/views/time.php',
        'includes/modules/reports/views/weekly.php',
        'includes/modules/reports/views/billing.php',
        'includes/modules/reports/views/worklog.php',
        'includes/modules/reports/views/rates.php',
        'includes/modules/reports/views/published.php',
        'includes/modules/reports/views/entry-modal.php',
    ];
}

function report_page_source_bundle(string $root): string
{
    $sources = [];
    foreach (report_page_source_files() as $relative_path) {
        $source = file_get_contents($root . '/' . $relative_path);
        if ($source === false) {
            throw new RuntimeException('Unable to read ' . $relative_path);
        }
        $sources[] = $source;
    }

    return implode("\n", $sources);
}
