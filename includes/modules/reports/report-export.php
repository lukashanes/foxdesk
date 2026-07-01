<?php
/**
 * Report export helpers.
 */

function report_export_csv_if_requested(array $request, string $tab, array $entries, array $by_org, bool $tags_supported, bool $show_money, bool $has_cost_data): void
{
    $export_tab = report_filter_normalize_tab($tab);
    $legacy_tab = $export_tab === 'billing' ? 'detailed' : ($export_tab === 'time' ? 'summary' : $export_tab);

    if (($request['export'] ?? '') !== 'csv' || !in_array($legacy_tab, ['detailed', 'worklog', 'summary'], true)) {
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $export_tab . '-' . date('Y-m-d') . '.csv"');
    $csv = fopen('php://output', 'w');
    if ($csv === false) {
        exit;
    }
    fwrite($csv, "\xEF\xBB\xBF");

    if ($legacy_tab === 'detailed') {
        $headers = [t('Row type'), t('Ticket'), t('Company')];
        if ($tags_supported) {
            $headers[] = t('Tags');
        }
        $headers = array_merge($headers, [t('Duration'), t('Duration (min)'), t('Billable'), t('Billable (min)'), t('Agent'), t('Source'), t('Start time'), t('End time'), t('Comment')]);
        if ($show_money) {
            $headers = array_merge($headers, [t('Rate'), t('Amount')]);
        }
        if ($show_money && $has_cost_data) {
            $headers = array_merge($headers, [t('Cost'), t('Profit')]);
        }
        fputcsv($csv, $headers);

        $ticket_detail_model = function_exists('report_ticket_detail_model')
            ? report_ticket_detail_model($entries, ['show_financials' => $show_money, 'rounding_minutes' => 1], true)
            : ['tickets' => []];
        $entries_by_id = [];
        foreach ($entries as $raw_entry) {
            $entries_by_id[(int) ($raw_entry['id'] ?? 0)] = $raw_entry;
        }

        foreach ($ticket_detail_model['tickets'] as $ticket) {
            $summary_row = ['ticket_summary', $ticket['title'], $ticket['organization_name'] ?: ''];
            if ($tags_supported) {
                $summary_row[] = $ticket['tags'] ?? '';
            }
            $summary_row[] = format_duration_minutes($ticket['minutes']);
            $summary_row[] = $ticket['minutes'];
            $summary_row[] = '';
            $summary_row[] = $ticket['billable_minutes'];
            $summary_row[] = '';
            $summary_row[] = '';
            $summary_row[] = '';
            $summary_row[] = '';
            $summary_row[] = '';
            if ($show_money) {
                $summary_row[] = '';
                $summary_row[] = number_format((float) ($ticket['amount'] ?? 0), 2, '.', '');
            }
            if ($show_money && $has_cost_data) {
                $summary_row[] = '';
                $summary_row[] = '';
            }
            fputcsv($csv, $summary_row);

            foreach ($ticket['entries'] as $entry) {
                $source_entry = $entries_by_id[(int) ($entry['id'] ?? 0)] ?? [];

                $row = ['comment_detail', $ticket['title'], $ticket['organization_name'] ?: ''];
                if ($tags_supported) {
                    $row[] = $ticket['tags'] ?? '';
                }
                $row[] = format_duration_minutes($entry['duration_minutes']);
                $row[] = $entry['duration_minutes'];
                $row[] = !empty($source_entry['is_billable']) ? t('Yes') : t('No');
                $row[] = (int) ($entry['billable_minutes'] ?? 0);
                $row[] = $entry['agent_name'];
                $row[] = $entry['source'];
                $row[] = $entry['started_at'] ?? '';
                $row[] = $entry['ended_at'] ?? '';
                $row[] = $entry['comment_text'] !== '' ? $entry['comment_text'] : $entry['summary'];
                if ($show_money) {
                    $row[] = number_format((float) ($source_entry['billable_rate'] ?? 0), 2, '.', '');
                    $row[] = number_format((float) ($entry['amount'] ?? 0), 2, '.', '');
                }
                if ($show_money && $has_cost_data) {
                    $row[] = number_format((float) ($source_entry['cost_amount'] ?? 0), 2, '.', '');
                    $row[] = number_format((float) ($source_entry['profit'] ?? 0), 2, '.', '');
                }
                fputcsv($csv, $row);
            }
        }
    } elseif ($legacy_tab === 'worklog') {
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
    } elseif ($legacy_tab === 'summary') {
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
