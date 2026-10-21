<?php

/**
 * Validates, signs, previews, and atomically applies structured multi-day work
 * logs. Human-readable notes stay separate from dates and time values.
 */

function foxdesk_agent_temporal_text_matches(string $value): array
{
    $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($text === '') {
        return [];
    }
    $patterns = [
        'date' => '/(?<!\d)(?:20\d{2}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[.\/-]\s*\d{1,2}[.\/-]\s*20\d{2})(?!\d)/u',
        'clock_time' => '/(?<!\d)(?:[01]?\d|2[0-3]):[0-5]\d(?!\d)/u',
        'duration' => '/(?<![\pL\d])\d+(?:[.,]\d+)?\s*(?:min(?:uta|uty|ut|utes?)?|hod(?:ina|iny|in)?|h|hrs?|hours?)(?!\pL)/iu',
    ];
    $matches = [];
    foreach ($patterns as $type => $pattern) {
        if (preg_match($pattern, $text, $found) === 1) {
            $matches[$type] = $found[0];
        }
    }
    return $matches;
}

function foxdesk_agent_assert_structured_temporal_text(
    string $value,
    string $field,
    bool $allow_temporal_text = false
): void {
    if (!$allow_temporal_text && foxdesk_agent_temporal_text_matches($value) !== []) {
        throw new InvalidArgumentException(
            "{$field} contains date/time text. Put dates in worked_on and time in duration_minutes or exact timestamp fields."
        );
    }
}

function foxdesk_agent_plan_date(string $value, string $field = 'worked_on'): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0))) {
        throw new InvalidArgumentException("{$field} must use YYYY-MM-DD.");
    }
    return $date->format('Y-m-d');
}

function foxdesk_agent_plan_datetime(string $value, string $field): string
{
    if (function_exists('foxdesk_normalize_backdated_datetime_input')) {
        $normalized = foxdesk_normalize_backdated_datetime_input($value);
        if ($normalized !== false && $normalized !== null) {
            return $normalized;
        }
    }
    throw new InvalidArgumentException("{$field} is invalid.");
}

function foxdesk_agent_plan_bool(array $source, string $key, bool $default): bool
{
    if (!array_key_exists($key, $source)) {
        return $default;
    }
    $value = $source[$key];
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int) $value === 1;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function foxdesk_agent_plan_canonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('foxdesk_agent_plan_canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = foxdesk_agent_plan_canonicalize($item);
    }
    return $value;
}

function foxdesk_agent_plan_signature(array $plan, array $user): string
{
    $secret = defined('SECRET_KEY') ? trim((string) SECRET_KEY) : '';
    if ($secret === '') {
        throw new RuntimeException('Plan signing is not configured.');
    }
    $identity = (int) ($user['tenant_id'] ?? 0) . ':' . (int) ($user['id'] ?? 0) . ':';
    $json = json_encode(
        foxdesk_agent_plan_canonicalize($plan),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    return hash_hmac('sha256', $identity . $json, $secret);
}

function foxdesk_agent_normalize_work_log_plan(array $input, array $user): array
{
    $structure = strtolower(trim((string) ($input['structure'] ?? '')));
    if (!in_array($structure, ['one_ticket', 'multiple_tickets'], true)) {
        throw new InvalidArgumentException('structure must be one_ticket or multiple_tickets.');
    }
    $allocation_basis = strtolower(trim((string) ($input['allocation_basis'] ?? '')));
    if (!in_array($allocation_basis, ['actual', 'approved_total'], true)) {
        throw new InvalidArgumentException('allocation_basis must be actual or approved_total.');
    }
    $expected_total = (int) ($input['total_minutes'] ?? 0);
    if ($expected_total < 1 || $expected_total > 525600) {
        throw new InvalidArgumentException('total_minutes is required and must be between 1 and 525600.');
    }
    $allow_temporal_text = foxdesk_agent_plan_bool($input, 'allow_temporal_text', false);
    if ($allow_temporal_text && trim((string) ($input['temporal_text_reason'] ?? '')) === '') {
        throw new InvalidArgumentException('temporal_text_reason is required when allow_temporal_text is true.');
    }

    $source_tickets = $input['tickets'] ?? null;
    if (!is_array($source_tickets) || $source_tickets === [] || !array_is_list($source_tickets)) {
        throw new InvalidArgumentException('tickets must be a non-empty list.');
    }
    if ($structure === 'one_ticket' && count($source_tickets) !== 1) {
        throw new InvalidArgumentException('one_ticket structure requires exactly one ticket.');
    }
    if ($structure === 'multiple_tickets' && count($source_tickets) < 2) {
        throw new InvalidArgumentException('multiple_tickets structure requires at least two tickets.');
    }

    $normalized_tickets = [];
    $all_durations = [];
    $seen_content = [];
    $computed_total = 0;
    foreach ($source_tickets as $ticket_index => $ticket) {
        if (!is_array($ticket)) {
            throw new InvalidArgumentException("tickets[{$ticket_index}] must be an object.");
        }
        $title = trim((string) ($ticket['title'] ?? ''));
        $description = trim((string) ($ticket['description'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException("tickets[{$ticket_index}].title is required.");
        }
        foxdesk_agent_assert_structured_temporal_text($title, "tickets[{$ticket_index}].title", $allow_temporal_text);
        foxdesk_agent_assert_structured_temporal_text($description, "tickets[{$ticket_index}].description", $allow_temporal_text);

        $entries = $ticket['entries'] ?? null;
        if (!is_array($entries) || $entries === [] || !array_is_list($entries)) {
            throw new InvalidArgumentException("tickets[{$ticket_index}].entries must be a non-empty list.");
        }
        $normalized_entries = [];
        foreach ($entries as $entry_index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException("tickets[{$ticket_index}].entries[{$entry_index}] must be an object.");
            }
            $content = trim((string) ($entry['content'] ?? ''));
            if ($content === '') {
                throw new InvalidArgumentException("tickets[{$ticket_index}].entries[{$entry_index}].content is required.");
            }
            foxdesk_agent_assert_structured_temporal_text(
                $content,
                "tickets[{$ticket_index}].entries[{$entry_index}].content",
                $allow_temporal_text
            );
            $content_key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($content))));
            if (isset($seen_content[$content_key])) {
                throw new InvalidArgumentException('Work-entry comments must be distinct; duplicate content was found.');
            }
            $seen_content[$content_key] = true;

            $duration = (int) ($entry['duration_minutes'] ?? 0);
            if ($duration < 1 || $duration > 1440) {
                throw new InvalidArgumentException('Each duration_minutes value must be between 1 and 1440.');
            }
            $precision = strtolower(trim((string) ($entry['time_precision'] ?? '')));
            if ($precision === '') {
                $precision = $allocation_basis === 'approved_total' ? 'allocated' : 'duration_only';
            }
            if (!in_array($precision, ['exact', 'duration_only', 'allocated'], true)) {
                throw new InvalidArgumentException('time_precision must be exact, duration_only, or allocated.');
            }
            if ($allocation_basis === 'approved_total' && $precision !== 'allocated') {
                throw new InvalidArgumentException('approved_total plans must mark every entry as allocated.');
            }
            if ($allocation_basis === 'actual' && $precision === 'allocated') {
                throw new InvalidArgumentException('actual plans cannot mark entries as allocated.');
            }

            $started_at = trim((string) ($entry['started_at'] ?? ''));
            $ended_at = trim((string) ($entry['ended_at'] ?? ''));
            if ($precision === 'exact') {
                if ($started_at === '' || $ended_at === '') {
                    throw new InvalidArgumentException('Exact entries require started_at and ended_at.');
                }
                $started_at = foxdesk_agent_plan_datetime($started_at, 'started_at');
                $ended_at = foxdesk_agent_plan_datetime($ended_at, 'ended_at');
                $seconds = strtotime($ended_at) - strtotime($started_at);
                if ($seconds <= 0 || (int) floor($seconds / 60) !== $duration) {
                    throw new InvalidArgumentException('Exact entry duration_minutes must match started_at and ended_at.');
                }
                $worked_on = foxdesk_agent_plan_date(
                    (string) ($entry['worked_on'] ?? date('Y-m-d', strtotime($started_at)))
                );
            } else {
                if ($started_at !== '' || $ended_at !== '') {
                    throw new InvalidArgumentException('Non-exact entries must not invent started_at or ended_at.');
                }
                $worked_on = foxdesk_agent_plan_date((string) ($entry['worked_on'] ?? ''));
                $started_at = null;
                $ended_at = null;
            }
            $summary = trim((string) ($entry['time_summary'] ?? ''));
            foxdesk_agent_assert_structured_temporal_text(
                $summary,
                "tickets[{$ticket_index}].entries[{$entry_index}].time_summary",
                $allow_temporal_text
            );
            $normalized_entries[] = [
                'worked_on' => $worked_on,
                'content' => function_exists('safe_html') ? safe_html($content) : $content,
                'duration_minutes' => $duration,
                'time_precision' => $precision,
                'started_at' => $started_at,
                'ended_at' => $ended_at,
                'time_summary' => $summary !== '' ? $summary : null,
                'is_billable' => foxdesk_agent_plan_bool($entry, 'is_billable', true),
                'is_internal' => foxdesk_agent_plan_bool($entry, 'is_internal', false),
                'skip_notification' => foxdesk_agent_plan_bool($entry, 'skip_notification', true),
            ];
            $all_durations[] = $duration;
            $computed_total += $duration;
        }
        usort($normalized_entries, static fn(array $a, array $b): int => [$a['worked_on'], $a['content']] <=> [$b['worked_on'], $b['content']]);
        $normalized_tickets[] = [
            'title' => $title,
            'description' => function_exists('safe_html') ? safe_html($description) : $description,
            'user_id' => max(0, (int) ($ticket['user_id'] ?? $user['id'] ?? 0)),
            'organization_id' => max(0, (int) ($ticket['organization_id'] ?? 0)) ?: null,
            'assignee_id' => max(0, (int) ($ticket['assignee_id'] ?? 0)) ?: null,
            'priority_id' => max(0, (int) ($ticket['priority_id'] ?? 0)) ?: null,
            'status_id' => max(0, (int) ($ticket['status_id'] ?? 0)) ?: null,
            'type' => trim((string) ($ticket['type'] ?? 'general')) ?: 'general',
            'entries' => $normalized_entries,
        ];
    }
    if ($computed_total !== $expected_total) {
        throw new InvalidArgumentException(
            "total_minutes does not match the entry sum ({$expected_total} expected, {$computed_total} planned)."
        );
    }
    if ($allocation_basis === 'approved_total' && count($all_durations) >= 3) {
        if (count(array_unique($all_durations)) < 2) {
            throw new InvalidArgumentException('Approved allocation must vary session durations instead of repeating one robotic block.');
        }
        if (count(array_filter($all_durations, static fn(int $minutes): bool => $minutes % 5 !== 0)) === 0) {
            throw new InvalidArgumentException('Approved allocation must include realistic minute-level precision, not only rounded blocks.');
        }
    }
    return [
        'schema_version' => 1,
        'structure' => $structure,
        'allocation_basis' => $allocation_basis,
        'total_minutes' => $expected_total,
        'allow_temporal_text' => $allow_temporal_text,
        'temporal_text_reason' => $allow_temporal_text ? trim((string) $input['temporal_text_reason']) : null,
        'tenant_id' => (int) ($user['tenant_id'] ?? 0),
        'user_id' => (int) ($user['id'] ?? 0),
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
        'tickets' => $normalized_tickets,
    ];
}

function foxdesk_agent_work_log_preview(array $plan): array
{
    $tickets = [];
    foreach ($plan['tickets'] as $ticket) {
        $entries = [];
        foreach ($ticket['entries'] as $entry) {
            $entries[] = [
                'worked_on' => $entry['worked_on'],
                'duration_minutes' => (int) $entry['duration_minutes'],
                'time_precision' => $entry['time_precision'],
                'content_text' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $entry['content']))),
            ];
        }
        $tickets[] = [
            'title' => $ticket['title'],
            'description_text' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $ticket['description']))),
            'entries' => $entries,
            'ticket_total_minutes' => array_sum(array_column($entries, 'duration_minutes')),
        ];
    }
    return [
        'structure' => $plan['structure'],
        'allocation_basis' => $plan['allocation_basis'],
        'tickets' => $tickets,
        'total_minutes' => (int) $plan['total_minutes'],
        'requires_user_confirmation' => true,
        'confirmation_instruction' => 'Show this complete preview to the user. Apply only after explicit confirmation.',
    ];
}

function foxdesk_agent_validate_work_log_ticket_references(array $ticket, array $user): array
{
    $owner_id = (int) ($ticket['user_id'] ?? $user['id'] ?? 0);
    $owner = get_user($owner_id);
    if (!$owner || !can_user_create_ticket_for($owner, $user)) {
        throw new InvalidArgumentException('Invalid ticket owner for this token.');
    }
    if (!empty($ticket['organization_id'])) {
        $organization_id = (int) $ticket['organization_id'];
        if (!get_organization($organization_id) || !can_user_use_organization($organization_id, $user)) {
            throw new InvalidArgumentException('Invalid organization for this token.');
        }
    }
    if (!empty($ticket['assignee_id'])) {
        $assignee = get_user((int) $ticket['assignee_id']);
        if (!$assignee || !can_user_assign_to_staff($assignee, $user)) {
            throw new InvalidArgumentException('Invalid assignee for this token.');
        }
    }
    if (!empty($ticket['priority_id']) && !get_priority((int) $ticket['priority_id'])) {
        throw new InvalidArgumentException('Priority not found.');
    }
    if (!empty($ticket['status_id']) && !get_status((int) $ticket['status_id'])) {
        throw new InvalidArgumentException('Status not found.');
    }
    return $ticket;
}

function api_agent_plan_work_log(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }
    foreach (['tickets:read', 'tickets:write', 'comments:write', 'time:write'] as $scope) {
        if (!empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope($scope)) {
            api_error("Missing required scope: {$scope}", 403);
        }
    }
    try {
        $user = current_user();
        $plan = foxdesk_agent_normalize_work_log_plan(get_json_input(), $user);
        foreach ($plan['tickets'] as $ticket) {
            foxdesk_agent_validate_work_log_ticket_references($ticket, $user);
        }
        api_success([
            'plan' => $plan,
            'plan_hash' => foxdesk_agent_plan_signature($plan, $user),
            'preview' => foxdesk_agent_work_log_preview($plan),
        ]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (Throwable $e) {
        api_error('Unable to prepare work-log plan.', 500);
    }
}

function api_agent_apply_work_log_plan(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }
    foreach (['tickets:read', 'tickets:write', 'comments:write', 'time:write'] as $scope) {
        if (!empty($GLOBALS['is_api_token_auth']) && !api_token_has_scope($scope)) {
            api_error("Missing required scope: {$scope}", 403);
        }
    }
    $input = get_json_input();
    if (!foxdesk_agent_plan_bool($input, 'confirm', false)) {
        api_error('Explicit confirm:true is required after the user approves the complete preview.', 422);
    }
    if (!is_array($input['plan'] ?? null) || trim((string) ($input['plan_hash'] ?? '')) === '') {
        api_error('plan and plan_hash are required.', 422);
    }
    $user = current_user();
    $plan = $input['plan'];
    try {
        $expected_hash = foxdesk_agent_plan_signature($plan, $user);
    } catch (Throwable $e) {
        api_error('Unable to verify work-log plan.', 500);
    }
    if (!hash_equals($expected_hash, trim((string) $input['plan_hash']))) {
        api_error('Plan hash is invalid or the preview changed after approval.', 409);
    }
    if ((int) ($plan['tenant_id'] ?? 0) !== (int) ($user['tenant_id'] ?? 0)
        || (int) ($plan['user_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
        api_error('Plan identity does not match the current token.', 403);
    }
    if (strtotime((string) ($plan['expires_at'] ?? '')) < time()) {
        api_error('Plan preview expired. Prepare and confirm a new preview.', 409);
    }

    $db = get_db();
    $owns_transaction = false;
    $created = [];
    try {
        foreach ($plan['tickets'] as $ticket) {
            foxdesk_agent_validate_work_log_ticket_references($ticket, $user);
        }
        $owns_transaction = db_begin_owned($db);
        $applied_total = 0;
        foreach ($plan['tickets'] as $ticket) {
            $ticket_data = [
                'title' => $ticket['title'],
                'description' => $ticket['description'],
                'user_id' => (int) $ticket['user_id'],
                'type' => $ticket['type'] ?? 'general',
            ];
            foreach (['organization_id', 'assignee_id', 'priority_id', 'status_id'] as $field) {
                if (!empty($ticket[$field])) {
                    $ticket_data[$field] = (int) $ticket[$field];
                }
            }
            $ticket_id = (int) create_ticket($ticket_data);
            if ($ticket_id <= 0) {
                throw new RuntimeException('Failed to create planned ticket.');
            }
            $entry_ids = [];
            $comment_ids = [];
            foreach ($ticket['entries'] as $entry_index => $entry) {
                $comment_created_at = $entry['worked_on'] . ' 12:' . str_pad((string) ($entry_index % 60), 2, '0', STR_PAD_LEFT) . ':00';
                $comment_id = (int) add_comment(
                    $ticket_id,
                    (int) $user['id'],
                    (string) $entry['content'],
                    !empty($entry['is_internal']) ? 1 : 0,
                    [
                        'created_at' => $comment_created_at,
                        'time_spent' => (int) $entry['duration_minutes'],
                    ]
                );
                if ($comment_id <= 0) {
                    throw new RuntimeException('Failed to create planned work comment.');
                }
                if ($entry['time_precision'] === 'exact') {
                    $started_at = $entry['started_at'];
                    $ended_at = $entry['ended_at'];
                } else {
                    $ended_at = $entry['worked_on'] . ' 12:00:00';
                    $started_at = date('Y-m-d H:i:s', strtotime($ended_at) - ((int) $entry['duration_minutes'] * 60));
                }
                $entry_id = (int) add_manual_time_entry($ticket_id, (int) $user['id'], [
                    'comment_id' => $comment_id,
                    'worked_on' => $entry['worked_on'],
                    'time_precision' => $entry['time_precision'],
                    'started_at' => $started_at,
                    'ended_at' => $ended_at,
                    'duration_minutes' => (int) $entry['duration_minutes'],
                    'summary' => $entry['time_summary'],
                    'is_billable' => !empty($entry['is_billable']) ? 1 : 0,
                    'source' => (function_exists('is_ai_user') && is_ai_user((int) $user['id'])) ? 'ai' : 'manual',
                ]);
                if ($entry_id <= 0) {
                    throw new RuntimeException('Failed to create planned time entry.');
                }
                $comment_ids[] = $comment_id;
                $entry_ids[] = $entry_id;
                $applied_total += (int) $entry['duration_minutes'];
            }
            $ticket_row = db_fetch_one('SELECT id, hash FROM tickets WHERE id = ? LIMIT 1', [$ticket_id]);
            $created[] = [
                'ticket_id' => $ticket_id,
                'ticket_hash' => $ticket_row['hash'] ?? null,
                'ticket_code' => function_exists('api_agent_ticket_code') ? api_agent_ticket_code($ticket_id) : ('TK-' . $ticket_id),
                'ticket_url' => $ticket_row && function_exists('api_agent_ticket_url') ? api_agent_ticket_url($ticket_row) : null,
                'comment_ids' => $comment_ids,
                'time_entry_ids' => $entry_ids,
            ];
        }
        if ($applied_total !== (int) $plan['total_minutes']) {
            throw new RuntimeException('Applied time does not match the approved plan total.');
        }
        db_commit_owned($owns_transaction, $db);
        api_success([
            'applied' => true,
            'tickets' => $created,
            'total_minutes' => $applied_total,
            'verification' => [
                'ticket_count' => count($created),
                'comment_count' => array_sum(array_map(static fn(array $row): int => count($row['comment_ids']), $created)),
                'time_entry_count' => array_sum(array_map(static fn(array $row): int => count($row['time_entry_ids']), $created)),
                'all_time_entries_linked' => true,
            ],
        ]);
    } catch (InvalidArgumentException $e) {
        db_rollback_owned($owns_transaction, $db);
        api_error($e->getMessage(), 422);
    } catch (Throwable $e) {
        db_rollback_owned($owns_transaction, $db);
        api_error('Failed to apply the approved work-log plan.', 500);
    }
}
