<?php
/**
 * Ticket detail read-model helpers.
 *
 * Keep permission-shaped display data outside the route so both editions use
 * the same ticket detail mental model.
 */

function ticket_detail_visible_comments(array $comments, bool $is_agent): array
{
    if ($is_agent) {
        return array_values($comments);
    }

    return array_values(array_filter($comments, static function (array $comment): bool {
        return empty($comment['is_internal']);
    }));
}

function ticket_detail_visible_comment_ids(array $comments): array
{
    $ids = [];
    foreach ($comments as $comment) {
        if (isset($comment['id'])) {
            $ids[(int) $comment['id']] = true;
        }
    }

    return $ids;
}

function ticket_detail_visible_attachments(array $attachments, array $visible_comment_ids, bool $is_agent): array
{
    if ($is_agent) {
        return array_values($attachments);
    }

    return array_values(array_filter($attachments, static function (array $attachment) use ($visible_comment_ids): bool {
        return empty($attachment['comment_id']) || isset($visible_comment_ids[(int) $attachment['comment_id']]);
    }));
}

function ticket_detail_initial_attachments(array $attachments): array
{
    return array_values(array_filter($attachments, static function (array $attachment): bool {
        return empty($attachment['comment_id']);
    }));
}

function ticket_detail_comment_attachments(array $attachments, int $comment_id): array
{
    return array_values(array_filter($attachments, static function (array $attachment) use ($comment_id): bool {
        return (int) ($attachment['comment_id'] ?? 0) === $comment_id;
    }));
}

function ticket_detail_build_timeline(array $comments, array $time_entries): array
{
    $time_entries_by_comment = [];
    $orphan_time_entries = [];

    foreach ($time_entries as $entry) {
        $comment_id = (int) ($entry['comment_id'] ?? 0);
        if ($comment_id > 0) {
            if (!isset($time_entries_by_comment[$comment_id])) {
                $time_entries_by_comment[$comment_id] = [];
            }
            $time_entries_by_comment[$comment_id][] = $entry;
        } else {
            $orphan_time_entries[] = $entry;
        }
    }

    $timeline_items = [];
    foreach ($comments as $comment) {
        $timeline_items[] = [
            'type' => 'comment',
            'data' => $comment,
            'sort_time' => strtotime((string) ($comment['created_at'] ?? '')) ?: 0,
        ];
    }

    foreach ($orphan_time_entries as $entry) {
        $timeline_items[] = [
            'type' => 'time_entry',
            'data' => $entry,
            'sort_time' => strtotime((string) ($entry['started_at'] ?? '')) ?: 0,
        ];
    }

    usort($timeline_items, static function (array $a, array $b): int {
        return ((int) ($a['sort_time'] ?? 0)) <=> ((int) ($b['sort_time'] ?? 0));
    });

    return [
        'time_entries_by_comment' => $time_entries_by_comment,
        'orphan_time_entries' => $orphan_time_entries,
        'timeline_items' => $timeline_items,
    ];
}
