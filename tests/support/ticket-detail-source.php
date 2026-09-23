<?php

function ticket_detail_browser_files(string $root): array
{
    return [
        $root . '/assets/js/ticket-detail-timer.js',
        $root . '/assets/js/ticket-detail-records.js',
        $root . '/assets/js/ticket-detail-admin.js',
        $root . '/assets/js/ticket-detail.js',
    ];
}

function ticket_detail_browser_source(string $root): string
{
    $sources = [];
    foreach (ticket_detail_browser_files($root) as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }
        $sources[] = $source;
    }

    return implode("\n", $sources);
}

function new_ticket_composed_source(string $root): string
{
    $sources = [];
    foreach ([
        $root . '/pages/new-ticket.php',
        $root . '/includes/components/new-ticket-form.php',
        $root . '/includes/components/new-ticket-assets.php',
    ] as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('Unable to read ' . $file);
        }
        $sources[] = $source;
    }

    return implode("\n", $sources);
}
