<?php
/**
 * Time overview read models.
 */

function report_time_overview_work_log_rows(array $entries, int $limit = 120): array
{
    $rows = [];
    $limit = max(1, $limit);

    foreach (array_slice($entries, 0, $limit) as $entry) {
        $ticket_id = (int) ($entry['ticket_id'] ?? 0);
        $started_at = (string) ($entry['started_at'] ?? '');
        $ended_at = (string) ($entry['ended_at'] ?? '');
        $summary = trim((string) ($entry['summary'] ?? ''));
        $agent = trim((string) ($entry['first_name'] ?? '') . ' ' . (string) ($entry['last_name'] ?? ''));

        $rows[] = [
            'id' => (int) ($entry['id'] ?? 0),
            'ticket_id' => $ticket_id,
            'ticket_code' => $ticket_id > 0 && function_exists('get_ticket_code') ? get_ticket_code($ticket_id) : '',
            'ticket_title' => (string) ($entry['ticket_title'] ?? ''),
            'client' => (string) (($entry['organization_name'] ?? '') ?: t('-- No organization --')),
            'agent' => $agent !== '' ? $agent : t('Unknown agent'),
            'started_at' => $started_at,
            'ended_at' => $ended_at,
            'started_label' => $started_at !== '' ? date('d.m. H:i', strtotime($started_at)) : '-',
            'ended_label' => $ended_at !== '' ? date('H:i', strtotime($ended_at)) : '',
            'minutes' => (int) ($entry['actual_minutes'] ?? $entry['duration_minutes'] ?? 0),
            'summary' => $summary !== '' ? $summary : t('No note'),
            'is_billable' => !empty($entry['is_billable']),
        ];
    }

    return $rows;
}
