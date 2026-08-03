<?php
/**
 * Request orchestration for the self-hosted reports page.
 */

function report_admin_page_context(array $request, array $post, array $server): array
{
    $page_title = t('Time report');
    $page = 'admin';
    $current_user = current_user();

    if (!is_admin() && (!function_exists('can_view_time') || !can_view_time($current_user))) {
        flash(t('Access denied.'), 'error');
        redirect(function_exists('foxdesk_authenticated_home_page') ? foxdesk_authenticated_home_page() : 'dashboard');
    }

    $time_tracking_available = ticket_time_table_exists();
    if (function_exists('ensure_ticket_custom_billable_rate_column')) {
        ensure_ticket_custom_billable_rate_column();
    }
    if (function_exists('ensure_user_billable_rate_column')) {
        ensure_user_billable_rate_column();
    }
    if (function_exists('ensure_agent_client_billable_rates_table')) {
        ensure_agent_client_billable_rates_table();
    }

    $report_filter_state = report_filter_state_from_request($request, is_admin());
    $tab = $report_filter_state['tab'];
    $time_range = $report_filter_state['time_range'];
    $range_start = $report_filter_state['range_start'];
    $range_end = $report_filter_state['range_end'];
    $selected_orgs = $report_filter_state['selected_orgs'];
    $selected_agents = $report_filter_state['selected_agents'];
    $tags_supported = function_exists('ticket_tags_column_exists') && ticket_tags_column_exists();
    $selected_tags = $report_filter_state['selected_tags'];
    $selected_tags_csv = $report_filter_state['selected_tags_csv'];
    $show_money = $report_filter_state['show_money'];

    $organizations = get_organizations(true);
    $agents = db_fetch_all(
        "SELECT id, first_name, last_name FROM users WHERE role IN ('agent', 'admin') AND is_active = 1 ORDER BY first_name, last_name"
    );
    $rounding = get_billing_rounding_increment();
    $_ai_user_ids = function_exists('get_ai_user_ids') ? get_ai_user_ids() : [];

    if (($server['REQUEST_METHOD'] ?? 'GET') === 'POST' && is_admin()) {
        require_csrf_token();
        report_handle_admin_post_actions($post, $rounding);
    }

    $from_date = $range_start ? substr((string) $range_start, 0, 10) : '';
    $to_date = $range_end ? substr((string) $range_end, 0, 10) : '';
    $time_range_labels = report_page_time_range_labels($range_start, $range_end);

    $report_data = report_query_time_entries($report_filter_state, $current_user, $tags_supported, $rounding, $_ai_user_ids);
    $entries = $report_data['entries'];
    $totals = $report_data['totals'];
    $by_org = $report_data['by_org'];
    $by_agent = $report_data['by_agent'];
    $by_ticket = $report_data['by_ticket'];
    $by_week = $report_data['by_week'];
    $by_source = $report_data['by_source'];
    $billable_time_notice = $tab === 'billing' ? report_billable_time_notice($totals, $rounding) : null;
    $has_cost_data = abs((float) ($totals['cost_amount'] ?? 0)) > 0.001;
    $billing_ticket_details = ($tab === 'billing' && $entries)
        ? report_ticket_detail_model($entries, [
            'show_financials' => (bool) $show_money,
            'show_team_attribution' => true,
            'show_cost_breakdown' => (bool) ($show_money && $has_cost_data),
            'rounding_minutes' => 1,
        ], false)
        : ['tickets' => [], 'ticket_count' => 0, 'entry_count' => 0];

    report_export_csv_if_requested($request, $tab, $entries, $by_org, $tags_supported, (bool) $show_money, $has_cost_data);

    $base_params = $request;
    $base_params['page'] = 'admin';
    $base_params['section'] = 'reports';
    $selected_client = report_page_selected_client($selected_orgs, $organizations);
    $selected_flow_org = $selected_client['id'];
    $selected_flow_org_name = $selected_client['name'];
    $selected_agent = report_page_selected_agent($selected_agents, $agents);
    $selected_flow_agent = $selected_agent['id'];
    $selected_flow_agent_name = $selected_agent['name'];
    $report_period_label = $time_range_labels[$time_range] ?? $time_range;
    $report_export_params = $base_params;
    $report_export_params['export'] = 'csv';
    $billing_col_defs = $tab === 'billing'
        ? report_page_billing_columns(is_admin(), $tags_supported, (bool) $show_money, $has_cost_data)
        : [];

    $active_filter_model = report_page_active_filters($report_filter_state, $organizations, $agents, $current_user);
    $active_filters = $active_filter_model['items'];
    $has_active_filters = $active_filter_model['has_items'];
    $filter_collapsed = $active_filter_model['collapsed'];
    $filter_summary_text = $active_filter_model['summary'];
    $worklog_days = report_page_worklog_model($entries);
    $weekly_model = report_page_weekly_model($by_week);
    $report_page_config = report_page_client_script_config($organizations, $agents, $report_filter_state, $tags_supported);

    $context = get_defined_vars();
    unset($context['request'], $context['post'], $context['server'], $context['context']);

    return $context;
}
