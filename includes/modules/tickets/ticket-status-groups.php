<?php
/**
 * Ticket status groups.
 *
 * Internal groups stay English and stable. UI labels are translated elsewhere.
 * Custom workflow statuses map into these groups so queues, search, reporting,
 * and notifications can share one mental model.
 */

function ticket_status_group_keys(): array
{
    return ['new', 'active', 'waiting', 'done', 'archived'];
}

function ticket_status_group_default_labels(): array
{
    return [
        'new' => 'New',
        'active' => 'Active',
        'waiting' => 'Waiting',
        'done' => 'Done',
        'archived' => 'Archived',
    ];
}

function ticket_status_group_normalize(?string $group): string
{
    $group = strtolower(trim((string) $group));
    return in_array($group, ticket_status_group_keys(), true) ? $group : 'active';
}

function ticket_status_group_search_text(?string $text): string
{
    $text = trim((string) $text);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    return strtr($text, [
        'Á' => 'a',
        'Č' => 'c',
        'Ď' => 'd',
        'É' => 'e',
        'Ě' => 'e',
        'Í' => 'i',
        'Ň' => 'n',
        'Ó' => 'o',
        'Ř' => 'r',
        'Š' => 's',
        'Ť' => 't',
        'Ú' => 'u',
        'Ů' => 'u',
        'Ý' => 'y',
        'Ž' => 'z',
        'á' => 'a',
        'č' => 'c',
        'ď' => 'd',
        'é' => 'e',
        'ě' => 'e',
        'í' => 'i',
        'ň' => 'n',
        'ó' => 'o',
        'ř' => 'r',
        'š' => 's',
        'ť' => 't',
        'ú' => 'u',
        'ů' => 'u',
        'ý' => 'y',
        'ž' => 'z',
    ]);
}

function ticket_status_group_from_status(array $status): string
{
    if (isset($status['status_group']) && trim((string) $status['status_group']) !== '') {
        return ticket_status_group_normalize((string) $status['status_group']);
    }

    if (!empty($status['is_closed'])) {
        return 'done';
    }

    $name = ticket_status_group_search_text($status['name'] ?? '');
    if ($name === '') {
        return 'active';
    }

    if (preg_match('/\b(new|open|todo|to do|received|created)\b/u', $name)) {
        return 'new';
    }
    if (preg_match('/\b(wait|waiting|pending|hold|blocked|client|customer|vendor|third party)\b/u', $name)) {
        return 'waiting';
    }
    if (preg_match('/\b(done|closed|resolved|complete|completed|finished|hotovo|dokonceno|vyreseno|uzavreno)\b/u', $name)) {
        return 'done';
    }

    return 'active';
}

function ticket_status_group_status_is_canceled(array $status): bool
{
    $text = ticket_status_group_search_text(implode(' ', array_filter([
        (string) ($status['name'] ?? ''),
        (string) ($status['slug'] ?? ''),
        (string) ($status['key'] ?? ''),
        (string) ($status['code'] ?? ''),
    ])));

    if ($text === '') {
        return false;
    }

    return (bool) preg_match('/\b(cancel|canceled|cancelled|storno|zrusen|reject|rejected|decline|declined|void|trash)\b/u', $text);
}

function ticket_status_group_done_status_score(array $status): int
{
    if (empty($status['id']) || ticket_status_group_status_is_canceled($status)) {
        return -1;
    }

    $text = ticket_status_group_search_text(implode(' ', array_filter([
        (string) ($status['name'] ?? ''),
        (string) ($status['slug'] ?? ''),
        (string) ($status['key'] ?? ''),
        (string) ($status['code'] ?? ''),
    ])));

    if ($text === 'done') {
        return 120;
    }
    if (preg_match('/\bdone\b/u', $text)) {
        return 110;
    }
    if (preg_match('/\b(complete|completed|hotovo|dokonceno)\b/u', $text)) {
        return 100;
    }
    if (preg_match('/\b(resolve|resolved|vyreseno)\b/u', $text)) {
        return 90;
    }
    if (preg_match('/\b(close|closed|uzavreno)\b/u', $text)) {
        return 80;
    }
    if (preg_match('/\b(finish|finished)\b/u', $text)) {
        return 70;
    }

    return ticket_status_group_from_status($status) === 'done' ? 50 : -1;
}

function ticket_status_group_visible_workflow_statuses(array $statuses, array $preserve_ids = []): array
{
    $preserve_lookup = array_flip(array_map('intval', $preserve_ids));
    $best_done_id = null;
    $best_done_score = -1;

    foreach ($statuses as $status) {
        $score = ticket_status_group_done_status_score($status);
        if ($score > $best_done_score) {
            $best_done_id = (int) ($status['id'] ?? 0);
            $best_done_score = $score;
        }
    }

    $visible = [];
    foreach ($statuses as $status) {
        $status_id = (int) ($status['id'] ?? 0);
        $preserve = isset($preserve_lookup[$status_id]);

        if (!$preserve && ticket_status_group_status_is_canceled($status)) {
            continue;
        }

        if (!$preserve
            && ticket_status_group_from_status($status) === 'done'
            && $best_done_id !== null
            && $status_id !== $best_done_id) {
            continue;
        }

        $visible[] = $status;
    }

    return $visible;
}

function ticket_status_group_display_name(array $status): string
{
    $name = trim((string) ($status['name'] ?? ''));
    $search_name = ticket_status_group_search_text($name);
    if (ticket_status_group_from_status($status) === 'done'
        || ticket_status_group_status_is_canceled($status)
        || preg_match('/\b(close|closed|uzavreno)\b/u', $search_name)) {
        return function_exists('t') ? t('Done') : 'Done';
    }

    return $name !== '' ? $name : (function_exists('t') ? t('Status') : 'Status');
}

function ticket_status_group_for_status_id(?int $status_id): string
{
    if (!$status_id || !function_exists('get_status')) {
        return 'active';
    }

    $status = get_status($status_id);
    return $status ? ticket_status_group_from_status($status) : 'active';
}

function ticket_status_group_is_customer_waiting(string $group): bool
{
    return ticket_status_group_normalize($group) === 'waiting';
}

function ticket_status_group_is_done(string $group): bool
{
    return ticket_status_group_normalize($group) === 'done';
}
