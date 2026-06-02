<?php
/**
 * Reporting flow helpers.
 *
 * Keep the billing/report publishing workflow small and reusable so the admin
 * reports page can stay focused on rendering data.
 */

function reporting_flow_steps(): array
{
    return [
        [
            'key' => 'client',
            'label' => t('Choose client'),
            'description' => t('Start with one client and one billing period.'),
        ],
        [
            'key' => 'review',
            'label' => t('Review billable items'),
            'description' => t('Open the detailed report with money columns visible.'),
        ],
        [
            'key' => 'adjust',
            'label' => t('Adjust rates or discounts'),
            'description' => t('Edit individual rows before sharing the result.'),
        ],
        [
            'key' => 'share',
            'label' => t('Share or export'),
            'description' => t('Create a client-facing report when the numbers are final.'),
        ],
    ];
}

function reporting_flow_time_presets(): array
{
    return [
        'this_month' => t('This month'),
        'last_month' => t('Last month'),
        'last_30_days' => t('Last 30 days'),
        'this_quarter' => t('This quarter'),
    ];
}

function reporting_flow_bounds(string $time_range): array
{
    $bounds = get_time_range_bounds($time_range);
    $from = $bounds['start'] ? substr((string) $bounds['start'], 0, 10) : date('Y-m-01');
    $to = $bounds['end'] ? substr((string) $bounds['end'], 0, 10) : date('Y-m-t');

    return [
        'range' => (string) ($bounds['range'] ?? $time_range),
        'from' => $from,
        'to' => $to,
    ];
}

function reporting_flow_review_url(?int $organization_id = null, string $time_range = 'this_month'): string
{
    $params = [
        'section' => 'reports',
        'tab' => 'detailed',
        'time_range' => $time_range,
        'show_money' => 1,
    ];

    if ($organization_id !== null && $organization_id > 0) {
        $params['organizations[]'] = $organization_id;
    }

    return url('admin', $params);
}

function reporting_flow_builder_url(?int $organization_id = null, string $time_range = 'this_month'): string
{
    $bounds = reporting_flow_bounds($time_range);
    $params = [
        'section' => 'report-builder',
        'date_from' => $bounds['from'],
        'date_to' => $bounds['to'],
    ];

    if ($organization_id !== null && $organization_id > 0) {
        $params['organization_id'] = $organization_id;
    }

    return url('admin', $params);
}
