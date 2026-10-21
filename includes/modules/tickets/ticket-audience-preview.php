<?php
/**
 * Read-only audience projection of a ticket.
 *
 * Shows staff what another participant (for example the client who opened
 * the ticket) sees. It never changes the authenticated principal, never
 * writes notifications or read state, and renders no forms.
 */
function ticket_audience_preview_allowed(array $ticket, array $actor, ?array $target): bool
{
    return $target !== null
        && in_array($actor['role'] ?? '', ['admin', 'agent'], true)
        && !empty($target['is_active'])
        && can_see_ticket($ticket, $actor)
        && can_see_ticket($ticket, $target);
}

function ticket_audience_preview_filter(array $target, array $comments, array $attachments, array $time_entries): array
{
    $team = in_array($target['role'] ?? '', ['admin', 'agent'], true);
    $comments = array_values(array_filter($comments, static fn($comment) => $team || empty($comment['is_internal'])));
    $visible_ids = array_flip(array_column($comments, 'id'));
    $attachments = array_values(array_filter($attachments, static fn($attachment) => empty($attachment['comment_id']) || isset($visible_ids[$attachment['comment_id']])));
    return ['comments' => $comments, 'attachments' => $attachments, 'time_entries' => $team ? $time_entries : [], 'team' => $team];
}
