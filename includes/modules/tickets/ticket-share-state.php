<?php

/**
 * Ticket sharing read model.
 *
 * The route should not rebuild public-link and shared-access labels inline.
 */

function ticket_detail_share_status(?array $latest_share): string
{
    if (!$latest_share) {
        return 'none';
    }

    if (!empty($latest_share['is_revoked'])) {
        return 'revoked';
    }

    if (!empty($latest_share['expires_at']) && strtotime((string) $latest_share['expires_at']) <= time()) {
        return 'expired';
    }

    return 'active';
}

function ticket_detail_share_status_label(string $status): string
{
    if ($status === 'active') {
        return t('Active');
    }
    if ($status === 'expired') {
        return t('Expired');
    }
    if ($status === 'revoked') {
        return t('Revoked');
    }
    return t('None');
}

function ticket_detail_share_status_class(string $status): string
{
    if ($status === 'active') {
        return 'text-green-600';
    }
    if ($status === 'expired') {
        return 'text-orange-600';
    }
    if ($status === 'revoked') {
        return 'text-red-600';
    }
    return 'td-text-muted';
}

function ticket_detail_share_state(int $ticket_id, bool $include_shared_users, array &$session): array
{
    $shared_users = ($include_shared_users && function_exists('get_ticket_access_users'))
        ? get_ticket_access_users($ticket_id)
        : [];
    $shared_user_ids = array_map('intval', array_column($shared_users, 'id'));

    $latest_share = function_exists('get_latest_ticket_share')
        ? get_latest_ticket_share($ticket_id)
        : null;
    $share_status = ticket_detail_share_status(is_array($latest_share) ? $latest_share : null);

    $share_token = null;
    if (!empty($session['share_token']) && (int) ($session['share_token_ticket_id'] ?? 0) === $ticket_id) {
        $share_token = (string) $session['share_token'];
        unset($session['share_token'], $session['share_token_ticket_id']);
    }

    $share_url = ($share_token && function_exists('get_ticket_share_url'))
        ? get_ticket_share_url($share_token)
        : null;

    return [
        'shared_users' => $shared_users,
        'shared_user_ids' => $shared_user_ids,
        'latest_share' => $latest_share,
        'share_status' => $share_status,
        'share_token' => $share_token,
        'share_url' => $share_url,
        'share_status_label' => ticket_detail_share_status_label($share_status),
        'share_status_class' => ticket_detail_share_status_class($share_status),
    ];
}
