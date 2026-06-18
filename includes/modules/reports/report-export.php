<?php
/**
 * Report export helpers.
 */

function report_export_csv_if_requested(array $request, string $tab, array $entries, array $by_org, bool $tags_supported, bool $show_money, bool $has_cost_data): void
{
    if (($request['export'] ?? '') !== 'csv' || !in_array($tab, ['detailed', 'worklog', 'summary'], true)) {
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $tab . '-' . date('Y-m-d') . '.csv"');
    $csv = fopen('php://output', 'w');
    if ($csv === false) {
        exit;
    }
    fwrite($csv, "\xEF\xBB\xBF");

    if ($tab === 'detailed') {
        $headers = [t('Ticket'), t('Company')];
        if ($tags_supported) {
            $headers[] = t('Tags');
        }
        $headers = array_merge($headers, [t('Duration'), t('Duration (min)'), t('Billable'), t('Billable (min)'), t('Agent'), t('Source'), t('Start time'), t('End time')]);
        if ($show_money) {
            $headers = array_merge($headers, [t('Rate'), t('Amount')]);
        }
        if ($show_money && $has_cost_data) {
            $headers = array_merge($headers, [t('Cost'), t('Profit')]);
        }
        fputcsv($csv, $headers);

        foreach ($entries as $entry) {
            $row = [$entry['ticket_title'], $entry['organization_name'] ?: ''];
            if ($tags_supported) {
                $row[] = $entry['ticket_tags'] ?? '';
            }
            $row[] = format_duration_minutes($entry['actual_minutes']);
            $row[] = $entry['actual_minutes'];
            $row[] = !empty($entry['is_billable']) ? t('Yes') : t('No');
            $row[] = (int) ($entry['billable_minutes'] ?? 0);
            $row[] = trim($entry['first_name'] . ' ' . $entry['last_name']);
            $row[] = $entry['_source'] ?? '';
            $row[] = $entry['started_at'];
            $row[] = $entry['ended_at'] ?: '';
            if ($show_money) {
                $row[] = number_format((float) ($entry['billable_rate'] ?? 0), 2, '.', '');
                $row[] = number_format((float) ($entry['billable_amount'] ?? 0), 2, '.', '');
            }
            if ($show_money && $has_cost_data) {
                $row[] = number_format((float) ($entry['cost_amount'] ?? 0), 2, '.', '');
                $row[] = number_format((float) ($entry['profit'] ?? 0), 2, '.', '');
            }
            fputcsv($csv, $row);
        }
    } elseif ($tab === 'worklog') {
        fputcsv($csv, [t('Date'), t('Ticket'), t('Subject'), t('Company'), t('User'), t('Billable'), t('Start'), t('End'), t('Duration'), t('Duration (min)')]);
        foreach ($entries as $entry) {
            fputcsv($csv, [
                date('Y-m-d', strtotime($entry['started_at'])),
                function_exists('get_ticket_code') ? get_ticket_code($entry['ticket_id']) : $entry['ticket_id'],
                $entry['ticket_title'],
                $entry['organization_name'] ?: '',
                trim($entry['first_name'] . ' ' . $entry['last_name']),
                !empty($entry['is_billable']) ? t('Yes') : t('No'),
                date('H:i', strtotime($entry['started_at'])),
                $entry['ended_at'] ? date('H:i', strtotime($entry['ended_at'])) : '',
                format_duration_minutes($entry['actual_minutes']),
                $entry['actual_minutes'],
            ]);
        }
    } elseif ($tab === 'summary') {
        $headers = [t('Company'), t('Time'), t('Time (min)'), t('Billable time'), t('Billable (min)'), t('Amount')];
        if ($has_cost_data) {
            $headers = array_merge($headers, [t('Cost'), t('Profit')]);
        }
        fputcsv($csv, $headers);
        foreach ($by_org as $org) {
            $row = [
                $org['name'],
                format_duration_minutes($org['minutes']),
                $org['minutes'],
                format_duration_minutes($org['billable_minutes']),
                $org['billable_minutes'],
                number_format((float) ($org['billable_amount'] ?? 0), 2, '.', ''),
            ];
            if ($has_cost_data) {
                $row[] = number_format((float) ($org['cost_amount'] ?? 0), 2, '.', '');
                $row[] = number_format((float) ($org['profit'] ?? 0), 2, '.', '');
            }
            fputcsv($csv, $row);
        }
    }

    fclose($csv);
    exit;
}
