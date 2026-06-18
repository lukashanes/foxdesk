<?php

/**
 * Ticket detail context read model.
 */

function ticket_detail_tag_filter_url(array $ticket, string $tag_value): string
{
    $params = ['tags' => $tag_value];
    if (!empty($ticket['is_archived'])) {
        $params['archived'] = '1';
    }
    return url('tickets', $params);
}

function ticket_detail_available_organizations(array $ticket, array $user): array
{
    if (!is_agent()) {
        return [];
    }

    $organizations = get_organizations(true);
    if (is_admin()) {
        return $organizations;
    }

    $allowed_org_ids = get_user_organization_ids((int) $user['id']);
    if (empty($allowed_org_ids)) {
        return $organizations;
    }

    $allowed_lookup = array_flip($allowed_org_ids);
    if (!empty($ticket['organization_id'])) {
        $allowed_lookup[(int) $ticket['organization_id']] = true;
    }

    return array_values(array_filter($organizations, static function ($organization) use ($allowed_lookup): bool {
        return isset($allowed_lookup[(int) ($organization['id'] ?? 0)]);
    }));
}

function ticket_detail_context(int $ticket_id, array $ticket, array $user, array &$session): array
{
    $all_comments = get_ticket_comments($ticket_id);
    $attachments = get_ticket_attachments($ticket_id);
    $statuses = get_statuses();
    $tags_supported = function_exists('ticket_tags_column_exists') && ticket_tags_column_exists();
    $ticket_tags = $tags_supported ? get_ticket_tags_array($ticket['tags'] ?? '') : [];
    $share_state = ticket_detail_share_state($ticket_id, is_agent(), $session);

    return [
        'all_comments' => $all_comments,
        'attachments' => $attachments,
        'statuses' => $statuses,
        'tags_supported' => $tags_supported,
        'organizations' => ticket_detail_available_organizations($ticket, $user),
        'ticket_tags' => $ticket_tags,
        'all_users' => is_agent() ? get_all_users() : [],
        'share_state' => $share_state,
    ];
}
