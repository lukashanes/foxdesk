<?php
/**
 * Report POST action handlers.
 */

function report_redirect_current(string $fallback_tab = 'detailed'): void
{
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports', 'tab' => $fallback_tab])));
    exit;
}

function report_redirect_rates(): void
{
    header('Location: ' . url('admin', ['section' => 'reports', 'tab' => 'rates']));
    exit;
}

function report_redirect_shared(): void
{
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports', 'tab' => 'shared'])));
    exit;
}

function report_selected_billable_entries(array $entry_ids): array
{
    $entry_ids = array_values(array_unique(array_filter(array_map('intval', $entry_ids), static fn (int $id): bool => $id > 0)));
    if (empty($entry_ids)) {
        return [];
    }

    $params = $entry_ids;
    $tenant_filter = '';
    if (function_exists('column_exists') && column_exists('ticket_time_entries', 'tenant_id') && function_exists('current_tenant_id')) {
        $tenant_filter = ' AND tte.tenant_id = ?';
        $params[] = current_tenant_id();
    }

    return db_fetch_all(
        "SELECT tte.*,
                t.organization_id,
                t.custom_billable_rate as ticket_custom_billable_rate,
                o.billable_rate as org_billable_rate
         FROM ticket_time_entries tte
         JOIN tickets t ON tte.ticket_id = t.id
         LEFT JOIN organizations o ON t.organization_id = o.id
         WHERE tte.id IN (" . implode(',', array_fill(0, count($entry_ids), '?')) . ")$tenant_filter",
        $params
    );
}

function report_update_entry_rate(int $entry_id, float $rate): void
{
    db_update('ticket_time_entries', [
        'is_billable' => 1,
        'billable_rate' => round(max(0, $rate), 2),
    ], 'id = ?', [$entry_id]);
}

function report_entry_rounded_actual_minutes(array $entry, int $rounding): int
{
    $actual_minutes = report_entry_enrich($entry, $rounding)['actual_minutes'];
    return function_exists('round_minutes_nearest') ? round_minutes_nearest($actual_minutes, $rounding) : $actual_minutes;
}

function report_rate_for_adjustment(array $entry, string $action, float $value, int $rounding, ?int $shared_billable_minutes = null): ?float
{
    if ($action === 'set_rate') {
        return $value;
    }

    if ($action === 'discount_percent') {
        if ($value < 0 || $value > 100) {
            return null;
        }
        $current_rate = function_exists('get_time_entry_effective_billable_rate')
            ? get_time_entry_effective_billable_rate($entry)
            : (float) ($entry['billable_rate'] ?? 0);
        return $current_rate * (1 - ($value / 100));
    }

    if ($action === 'discount_amount') {
        return function_exists('billing_review_adjusted_rate')
            ? billing_review_adjusted_rate($entry, 'discount_amount', $value, $rounding)
            : null;
    }

    if ($action === 'target_total') {
        $minutes = $shared_billable_minutes;
        if ($minutes === null) {
            $minutes = report_entry_rounded_actual_minutes($entry, $rounding);
        }
        return function_exists('billing_review_rate_from_target_amount')
            ? billing_review_rate_from_target_amount($value, $minutes)
            : ($minutes > 0 ? ($value / ($minutes / 60)) : null);
    }

    return null;
}

function report_handle_bulk_billable_update(array $post, int $rounding): void
{
    $entry_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($post['entry_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
    if (empty($entry_ids)) {
        flash(t('Select at least one billable item.'), 'error');
        report_redirect_current();
    }

    $entries = report_selected_billable_entries($entry_ids);
    if (empty($entries)) {
        flash(t('Select at least one billable item.'), 'error');
        report_redirect_current();
    }

    $action = (string) ($post['bulk_action'] ?? 'set_rate');
    $value = null;
    $shared_minutes = null;

    if ($action === 'set_rate') {
        $value = parse_optional_rate_value($post['bulk_rate'] ?? null);
        if ($value === null) {
            flash(t('Enter a valid hourly rate.'), 'error');
            report_redirect_current();
        }
    } elseif ($action === 'discount_percent') {
        $value = parse_optional_rate_value($post['bulk_discount_percent'] ?? null);
        if ($value === null || $value < 0 || $value > 100) {
            flash(t('Enter a valid discount percent.'), 'error');
            report_redirect_current();
        }
    } elseif ($action === 'discount_amount') {
        $value = parse_optional_rate_value($post['bulk_discount_amount'] ?? null);
        if ($value === null || $value < 0) {
            flash(t('Enter a valid discount amount.'), 'error');
            report_redirect_current();
        }
    } elseif ($action === 'target_total') {
        $value = parse_optional_rate_value($post['bulk_target_total'] ?? null);
        if ($value === null || $value < 0) {
            flash(t('Enter a valid target total.'), 'error');
            report_redirect_current();
        }
        $shared_minutes = 0;
        foreach ($entries as $entry) {
            $shared_minutes += report_entry_rounded_actual_minutes($entry, $rounding);
        }
        if ($shared_minutes <= 0) {
            flash(t('Selected items have no billable time.'), 'error');
            report_redirect_current();
        }
    } else {
        flash(t('Invalid bulk action.'), 'error');
        report_redirect_current();
    }

    $updated = 0;
    foreach ($entries as $entry) {
        $rate = report_rate_for_adjustment($entry, $action, (float) $value, $rounding, $shared_minutes);
        if ($rate === null) {
            continue;
        }
        report_update_entry_rate((int) $entry['id'], $rate);
        $updated++;
    }

    flash(sprintf(t('Billable item adjustments updated: %d.'), $updated), 'success');
    report_redirect_current();
}

function report_handle_single_billable_adjustment(array $post, int $rounding): void
{
    $entry_id = (int) ($post['entry_id'] ?? 0);
    $action = (string) ($post['entry_adjust_action'] ?? 'set_rate');
    $value = parse_optional_rate_value($post['entry_adjust_value'] ?? null);
    if ($entry_id <= 0 || $value === null) {
        flash(t('Enter a valid billing adjustment.'), 'error');
        report_redirect_current();
    }

    $entries = report_selected_billable_entries([$entry_id]);
    $entry = $entries[0] ?? null;
    if (!$entry) {
        flash(t('Time entry not found.'), 'error');
        report_redirect_current();
    }

    $rate = report_rate_for_adjustment($entry, $action, (float) $value, $rounding);
    if ($rate === null) {
        $message = $action === 'discount_percent' ? t('Enter a valid discount percent.') : t('Selected item has no billable time.');
        flash($message, 'error');
        report_redirect_current();
    }

    report_update_entry_rate($entry_id, $rate);
    flash(t('Billable item adjustment updated.'), 'success');
    report_redirect_current();
}

function report_handle_admin_post_actions(array $post, int $rounding): bool
{
    if (isset($post['save_agent_client_rate'])) {
        $organization_id = (int) ($post['organization_id'] ?? 0);
        $user_id = (int) ($post['user_id'] ?? 0);
        $rate = parse_optional_rate_value($post['billable_rate'] ?? null);
        $notes = trim((string) ($post['notes'] ?? ''));
        if ($organization_id > 0 && $user_id > 0 && $rate !== null && save_agent_client_billable_rate($organization_id, $user_id, $rate, $notes)) {
            flash(t('Settings saved.'), 'success');
        } else {
            flash(t('Please select a client, agent, and hourly rate.'), 'error');
        }
        report_redirect_rates();
    }

    if (isset($post['save_agent_default_rate'])) {
        $user_id = (int) ($post['user_id'] ?? 0);
        $rate = parse_optional_rate_value($post['billable_rate'] ?? null);
        if ($user_id > 0 && $rate !== null && function_exists('save_agent_default_billable_rate') && save_agent_default_billable_rate($user_id, $rate)) {
            flash(t('Settings saved.'), 'success');
        } else {
            flash(t('Please select an agent and hourly rate.'), 'error');
        }
        report_redirect_rates();
    }

    if (isset($post['delete_agent_client_rate'])) {
        $rate_id = (int) ($post['rate_id'] ?? 0);
        if ($rate_id > 0 && delete_agent_client_billable_rate($rate_id)) {
            flash(t('Rate deleted.'), 'success');
        }
        report_redirect_rates();
    }

    if (isset($post['bulk_update_billable_entries'])) {
        report_handle_bulk_billable_update($post, $rounding);
    }

    if (isset($post['adjust_billable_entry'])) {
        report_handle_single_billable_adjustment($post, $rounding);
    }

    if (isset($post['set_billable'])) {
        $entry_id = (int) ($post['entry_id'] ?? 0);
        $is_billable = isset($post['is_billable']) && $post['is_billable'] === '1' ? 1 : 0;
        if ($entry_id > 0) {
            $update_data = ['is_billable' => $is_billable];
            if ($is_billable && function_exists('get_ticket_effective_billable_rate')) {
                $entry_row = db_fetch_one("SELECT ticket_id, user_id FROM ticket_time_entries WHERE id = ?", [$entry_id]);
                if ($entry_row) {
                    $update_data['billable_rate'] = get_ticket_effective_billable_rate((int) $entry_row['ticket_id'], (int) $entry_row['user_id']);
                }
            }
            db_update('ticket_time_entries', $update_data, 'id = ?', [$entry_id]);
            flash(t('Settings saved.'), 'success');
        }
        report_redirect_current('summary');
    }

    if (isset($post['delete_entry'])) {
        $entry_id = (int) ($post['entry_id'] ?? 0);
        if ($entry_id > 0) {
            require_once BASE_PATH . '/includes/ticket-time-functions.php';
            $deleted = delete_time_entry($entry_id);
            flash($deleted ? t('Time entry deleted.') : t('Failed to delete time entry.'), $deleted ? 'success' : 'error');
        }
        report_redirect_current('summary');
    }

    if (isset($post['update_time_inline'])) {
        $entry_id = (int) ($post['entry_id'] ?? 0);
        $entry_date = $post['entry_date'] ?? date('Y-m-d');
        $start_time = $post['start_time'] ?? '';
        $end_time = $post['end_time'] ?? '';
        if ($entry_id > 0 && $start_time && $end_time) {
            $start_dt = DateTime::createFromFormat('Y-m-d H:i', $entry_date . ' ' . $start_time);
            $end_dt = DateTime::createFromFormat('Y-m-d H:i', $entry_date . ' ' . $end_time);
            if (!$start_dt || !$end_dt) {
                flash(t('Invalid time format.'), 'error');
                report_redirect_current('worklog');
            }
            if ($end_dt <= $start_dt) {
                $end_dt->modify('+1 day');
            }
            $duration = max(1, (int) floor(($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60));
            db_update('ticket_time_entries', [
                'started_at' => $start_dt->format('Y-m-d H:i:s'),
                'ended_at' => $end_dt->format('Y-m-d H:i:s'),
                'duration_minutes' => $duration,
            ], 'id = ?', [$entry_id]);
        }
        report_redirect_current('worklog');
    }

    if (isset($post['update_entry'])) {
        $entry_id = (int) ($post['entry_id'] ?? 0);
        $ticket_input = trim($post['ticket_id'] ?? '');
        $ticket_title = trim($post['ticket_title'] ?? '');
        $start_input = trim($post['started_at'] ?? '');
        $end_input = trim($post['ended_at'] ?? '');
        $ticket_id = null;
        if ($ticket_input !== '') {
            $parsed = parse_ticket_code($ticket_input);
            $ticket_id = $parsed !== null ? $parsed : (ctype_digit($ticket_input) ? (int) $ticket_input : null);
        }
        $ticket = $ticket_id ? get_ticket($ticket_id) : null;
        $start_dt = DateTime::createFromFormat('Y-m-d\\TH:i', $start_input);
        $end_dt = DateTime::createFromFormat('Y-m-d\\TH:i', $end_input);
        if (!$ticket || !$start_dt || !$end_dt || $end_dt <= $start_dt) {
            flash(t('Invalid time range.'), 'error');
            report_redirect_current('summary');
        }
        $update_data = [
            'ticket_id' => $ticket_id,
            'started_at' => $start_dt->format('Y-m-d H:i:s'),
            'ended_at' => $end_dt->format('Y-m-d H:i:s'),
            'duration_minutes' => max(0, (int) floor(($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60)),
        ];
        if (!empty($ticket_title) && $ticket['title'] !== $ticket_title) {
            db_update('tickets', ['title' => $ticket_title], 'id = ?', [$ticket_id]);
        }
        $current_entry = db_fetch_one("SELECT ticket_id, user_id FROM ticket_time_entries WHERE id = ?", [$entry_id]);
        if ($current_entry && (int) $current_entry['ticket_id'] !== $ticket_id) {
            $update_data['comment_id'] = null;
            $update_data['billable_rate'] = function_exists('get_ticket_effective_billable_rate')
                ? get_ticket_effective_billable_rate($ticket, (int) $current_entry['user_id'])
                : 0;
        }
        db_update('ticket_time_entries', $update_data, 'id = ?', [$entry_id]);
        flash(t('Settings saved.'), 'success');
        report_redirect_current('summary');
    }

    if (isset($post['create_report_share'])) {
        $org_id = (int) ($post['organization_id'] ?? 0);
        if ($org_id > 0) {
            $expires_input = trim($post['share_expires_at'] ?? '');
            $expires_at = null;
            if ($expires_input !== '') {
                $timestamp = strtotime($expires_input);
                if ($timestamp === false || $timestamp <= time()) {
                    flash(t('Expiration must be in the future.'), 'error');
                    report_redirect_shared();
                }
                $expires_at = date('Y-m-d H:i:s', $timestamp);
            }
            $share = create_report_share($org_id, current_user()['id'], $expires_at);
            $_SESSION['report_share_token'] = $share['token'];
            $_SESSION['report_share_org_id'] = $org_id;
            flash(t('Share link created.'), 'success');
        }
        report_redirect_shared();
    }

    if (isset($post['revoke_report_share'])) {
        $org_id = (int) ($post['organization_id'] ?? 0);
        if ($org_id > 0) {
            $revoked = revoke_report_shares($org_id);
            flash($revoked > 0 ? t('Share link revoked.') : t('No active share link to revoke.'), $revoked > 0 ? 'success' : 'error');
        }
        report_redirect_shared();
    }

    return false;
}
