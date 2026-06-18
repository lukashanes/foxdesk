<?php
/**
 * Ticket bulk actions.
 *
 * The route owns request entry and rendering; this module owns bulk archive,
 * delete, and update behavior.
 */

function ticket_bulk_action_redirect_params(array $request): array
{
    $params = [];
    foreach (['archived', 'work_view', 'status', 'priority', 'assignee', 'type', 'search', 'sort', 'view', 'tag', 'organization'] as $key) {
        if (isset($request[$key]) && $request[$key] !== '') {
            $params[$key] = $request[$key];
        }
    }
    return $params;
}

function ticket_bulk_editable_tickets($ticket_ids, array $user): array
{
    $editable = [];
    $unique_ids = array_values(array_unique(array_filter(array_map('intval', (array) $ticket_ids))));
    if (empty($unique_ids)) {
        return $editable;
    }

    $all_tickets = function_exists('get_tickets_by_ids') ? get_tickets_by_ids($unique_ids) : [];
    foreach ($unique_ids as $ticket_id) {
        $ticket_item = $all_tickets[$ticket_id] ?? null;
        if (!$ticket_item) {
            continue;
        }
        if (!can_see_ticket($ticket_item, $user) || !can_edit_ticket($ticket_item, $user)) {
            continue;
        }
        $editable[$ticket_id] = $ticket_item;
    }

    return $editable;
}

function ticket_bulk_delete_archived(array $editable_tickets): int
{
    $deleted_count = 0;
    foreach ($editable_tickets as $ticket_id => $ticket_item) {
        $attachments = get_ticket_attachments($ticket_id);
        foreach ($attachments as $attachment) {
            $path = attachment_absolute_path($attachment);
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
        if (delete_ticket($ticket_id)) {
            $deleted_count++;
        }
    }

    return $deleted_count;
}

function ticket_bulk_archive(array $editable_tickets, array $user): int
{
    $archived_count = 0;
    foreach ($editable_tickets as $ticket_id => $ticket_item) {
        if (db_update('tickets', ['is_archived' => 1], 'id = ?', [$ticket_id])) {
            log_activity($ticket_id, $user['id'], 'archived', 'Ticket archived via bulk action');
            $archived_count++;
        }
    }

    return $archived_count;
}

function ticket_bulk_update_data_from_post(array $post, array $redirect_params): array
{
    $organization_raw = (string) ($post['bulk_organization_id'] ?? '__keep__');
    $status_raw = (string) ($post['bulk_status_id'] ?? '');
    $priority_raw = (string) ($post['bulk_priority_id'] ?? '');
    $tags_mode = (string) ($post['bulk_tags_mode'] ?? 'keep');
    $tags_input = trim((string) ($post['bulk_tags'] ?? ''));

    $base_update_data = [];
    $has_update = false;

    if ($organization_raw !== '__keep__') {
        if ($organization_raw === '__none__') {
            $base_update_data['organization_id'] = null;
            $has_update = true;
        } else {
            $organization_id_candidate = (int) $organization_raw;
            $organization_exists = $organization_id_candidate > 0 && get_organization($organization_id_candidate);
            if (!$organization_exists) {
                flash(t('Selected organization is not available.'), 'error');
                redirect('tickets', $redirect_params);
            }
            $base_update_data['organization_id'] = $organization_id_candidate;
            $has_update = true;
        }
    }

    if ($status_raw !== '') {
        $status_id_candidate = (int) $status_raw;
        if ($status_id_candidate > 0 && get_status($status_id_candidate)) {
            $base_update_data['status_id'] = $status_id_candidate;
            $has_update = true;
        }
    }

    if ($priority_raw !== '') {
        $priority_id_candidate = (int) $priority_raw;
        if ($priority_id_candidate > 0 && get_priority($priority_id_candidate)) {
            $base_update_data['priority_id'] = $priority_id_candidate;
            $has_update = true;
        }
    }

    if (!in_array($tags_mode, ['keep', 'replace', 'append', 'clear'], true)) {
        $tags_mode = 'keep';
    }
    if (!(function_exists('ticket_tags_column_exists') && ticket_tags_column_exists())) {
        $tags_mode = 'keep';
    }
    if ($tags_mode !== 'keep') {
        $has_update = true;
    }

    return [
        'base_update_data' => $base_update_data,
        'has_update' => $has_update,
        'tags_mode' => $tags_mode,
        'tags_input' => $tags_input,
    ];
}

function ticket_bulk_update(array $editable_tickets, array $user, array $update_state): int
{
    $updated_count = 0;
    foreach ($editable_tickets as $ticket_id => $ticket_item) {
        $update_data = $update_state['base_update_data'];
        $tags_mode = (string) $update_state['tags_mode'];
        $tags_input = (string) $update_state['tags_input'];

        if ($tags_mode === 'replace') {
            $normalized = normalize_ticket_tags($tags_input);
            $update_data['tags'] = $normalized !== '' ? $normalized : null;
        } elseif ($tags_mode === 'append') {
            if ($tags_input !== '') {
                $normalized = normalize_ticket_tags(($ticket_item['tags'] ?? '') . ', ' . $tags_input);
                $update_data['tags'] = $normalized !== '' ? $normalized : null;
            }
        } elseif ($tags_mode === 'clear') {
            $update_data['tags'] = null;
        }

        if (!empty($update_data) && update_ticket_with_history($ticket_id, $update_data, $user['id'])) {
            log_activity($ticket_id, $user['id'], 'ticket_edited', 'Ticket updated via bulk action');
            $updated_count++;
        }
    }

    return $updated_count;
}

function ticket_handle_bulk_actions(string $method, array $post, array $user, bool $is_archive, array $redirect_params): void
{
    if ($method !== 'POST' || !is_agent()) {
        return;
    }

    require_csrf_token();
    $editable_tickets = ticket_bulk_editable_tickets($post['ticket_ids'] ?? [], $user);

    if (isset($post['bulk_delete']) && $is_archive) {
        $deleted_count = ticket_bulk_delete_archived($editable_tickets);
        flash($deleted_count > 0 ? t('Selected tickets were deleted.') : t('No tickets selected.'), $deleted_count > 0 ? 'success' : 'error');
        redirect('tickets', $redirect_params + ['archived' => '1']);
    }

    if (isset($post['bulk_archive']) && !$is_archive) {
        if (!column_exists('tickets', 'is_archived')) {
            flash(t('Archive is not available on this installation yet.'), 'error');
            redirect('tickets', $redirect_params);
        }

        $archived_count = ticket_bulk_archive($editable_tickets, $user);
        flash($archived_count > 0 ? t('{count} tickets moved to archive.', ['count' => $archived_count]) : t('No tickets selected.'), $archived_count > 0 ? 'success' : 'error');
        redirect('tickets', $redirect_params);
    }

    if (isset($post['bulk_update']) && !$is_archive) {
        $update_state = ticket_bulk_update_data_from_post($post, $redirect_params);
        if (!$update_state['has_update']) {
            flash(t('Select at least one field to update.'), 'error');
            redirect('tickets', $redirect_params);
        }

        $updated_count = ticket_bulk_update($editable_tickets, $user, $update_state);
        flash($updated_count > 0 ? t('{count} tickets updated.', ['count' => $updated_count]) : t('No tickets selected.'), $updated_count > 0 ? 'success' : 'error');
        redirect('tickets', $redirect_params);
    }
}
