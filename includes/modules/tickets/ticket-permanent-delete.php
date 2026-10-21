<?php

/**
 * Irreversible ticket deletion.
 *
 * Database records are removed atomically. Attachment objects are queued in
 * the same transaction and removed only after commit so a storage failure can
 * be retried without restoring customer data.
 */

function ticket_permanent_delete_ensure_tables(): void
{
    foreach (['ticket_deletion_receipts', 'ticket_storage_deletion_outbox'] as $table) {
        if (!table_exists($table)) {
            throw new RuntimeException('Database upgrade required before permanent ticket deletion.', 503);
        }
    }
}

function ticket_permanent_delete_ticket_code(int $ticket_id): string
{
    return function_exists('get_ticket_code') ? get_ticket_code($ticket_id) : ('TK-' . $ticket_id);
}

function ticket_permanent_delete_tenant_id(array $ticket): int
{
    return (int) ($ticket['tenant_id'] ?? (function_exists('current_tenant_id') ? current_tenant_id() : 0));
}

function ticket_permanent_delete_preflight(int $ticket_id): ?array
{
    if ($ticket_id <= 0) {
        return null;
    }

    $ticket = get_ticket($ticket_id);
    if (!$ticket) {
        return null;
    }

    $comment_count = (int) (db_fetch_one('SELECT COUNT(*) AS cnt FROM comments WHERE ticket_id = ?', [$ticket_id])['cnt'] ?? 0);
    $attachment_count = (int) (db_fetch_one('SELECT COUNT(*) AS cnt FROM attachments WHERE ticket_id = ?', [$ticket_id])['cnt'] ?? 0);
    $time_count = 0;
    $time_minutes = 0;
    if (function_exists('ticket_time_table_exists') && ticket_time_table_exists()) {
        $time = db_fetch_one(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(duration_minutes), 0) AS minutes FROM ticket_time_entries WHERE ticket_id = ?',
            [$ticket_id]
        ) ?: [];
        $time_count = (int) ($time['cnt'] ?? 0);
        $time_minutes = (int) ($time['minutes'] ?? 0);
    }

    return [
        'ticket_id' => $ticket_id,
        'ticket_code' => ticket_permanent_delete_ticket_code($ticket_id),
        'title' => (string) ($ticket['title'] ?? ''),
        'comment_count' => $comment_count,
        'time_entry_count' => $time_count,
        'time_minutes' => $time_minutes,
        'attachment_count' => $attachment_count,
    ];
}

function ticket_permanent_delete_receipt(int $ticket_id, int $tenant_id): ?array
{
    if (!table_exists('ticket_deletion_receipts')) {
        return null;
    }

    $row = db_fetch_one(
        'SELECT ticket_id, deleted_at, request_id FROM ticket_deletion_receipts WHERE ticket_id = ? AND tenant_id = ? LIMIT 1',
        [$ticket_id, $tenant_id]
    );
    return $row ?: null;
}

function ticket_permanent_delete_register_after_commit(callable $callback): void
{
    $GLOBALS['ticket_after_commit_callbacks'] ??= [];
    $GLOBALS['ticket_after_commit_callbacks'][] = $callback;
}

function ticket_permanent_delete_run_after_commit_callbacks(): void
{
    $callbacks = $GLOBALS['ticket_after_commit_callbacks'] ?? [];
    $GLOBALS['ticket_after_commit_callbacks'] = [];
    foreach ($callbacks as $callback) {
        try {
            $callback();
        } catch (Throwable $e) {
            error_log('Deferred ticket cleanup failed; the outbox will retry it.');
        }
    }
}

function ticket_permanent_delete_process_storage(array $outbox_ids): array
{
    $processed = 0;
    $pending = 0;
    foreach (array_values(array_unique(array_filter(array_map('intval', $outbox_ids)))) as $outbox_id) {
        $row = db_fetch_one('SELECT * FROM ticket_storage_deletion_outbox WHERE id = ? AND processed_at IS NULL', [$outbox_id]);
        if (!$row) {
            continue;
        }

        $attachment = json_decode((string) ($row['attachment_payload'] ?? ''), true);
        if (!is_array($attachment)) {
            db_update('ticket_storage_deletion_outbox', [
                'attempts' => (int) $row['attempts'] + 1,
                'last_error' => 'Invalid attachment cleanup payload',
            ], 'id = ?', [$outbox_id]);
            $pending++;
            continue;
        }

        try {
            $ok = function_exists('delete_attachment_storage') && delete_attachment_storage($attachment);
            if (!$ok && ($attachment['storage_driver'] ?? 'local') !== 'r2') {
                $path = function_exists('attachment_absolute_path') ? attachment_absolute_path($attachment) : '';
                $normalized_path = str_replace('\\', '/', $path);
                $normalized_base = rtrim(str_replace('\\', '/', BASE_PATH), '/');
                if ($path === '' || !is_file($path)) {
                    $ok = true;
                } elseif (str_starts_with($normalized_path, $normalized_base . '/')) {
                    $ok = unlink($path);
                }
            }
            if (!$ok) {
                throw new RuntimeException('Storage provider did not confirm deletion');
            }
            db_update('ticket_storage_deletion_outbox', [
                'attempts' => (int) $row['attempts'] + 1,
                'attachment_payload' => '{}',
                'last_error' => null,
                'processed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$outbox_id]);
            $processed++;
        } catch (Throwable $e) {
            db_update('ticket_storage_deletion_outbox', [
                'attempts' => (int) $row['attempts'] + 1,
                'last_error' => substr($e->getMessage(), 0, 500),
            ], 'id = ?', [$outbox_id]);
            $pending++;
        }
    }

    return ['processed' => $processed, 'pending' => $pending];
}

function ticket_permanent_delete_retry_pending_storage(int $limit = 25): array
{
    if (!table_exists('ticket_storage_deletion_outbox')) {
        return ['processed' => 0, 'pending' => 0, 'failed' => 0];
    }

    $limit = max(1, min(100, $limit));
    $rows = db_fetch_all(
        "SELECT id FROM ticket_storage_deletion_outbox
         WHERE processed_at IS NULL AND attempts < 10
         ORDER BY created_at ASC
         LIMIT {$limit}"
    );
    $result = ticket_permanent_delete_process_storage(array_column($rows, 'id'));
    $result['failed'] = (int) (db_fetch_one(
        'SELECT COUNT(*) AS cnt FROM ticket_storage_deletion_outbox WHERE processed_at IS NULL AND attempts >= 10'
    )['cnt'] ?? 0);
    return $result;
}

function ticket_permanent_delete_response_references_ticket($value, int $ticket_id): bool
{
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $key => $item) {
        if (in_array((string) $key, ['ticket_id', 'deleted_ticket_id'], true) && (int) $item === $ticket_id) {
            return true;
        }
        if (is_array($item) && ticket_permanent_delete_response_references_ticket($item, $ticket_id)) {
            return true;
        }
    }
    return false;
}

function ticket_permanent_delete_remove_idempotency_references(int $ticket_id, int $tenant_id): void
{
    if (!table_exists('api_idempotency_keys')) {
        return;
    }
    // Self-hosted api_idempotency_keys has no tenant_id column; scope only when it exists.
    $params = [];
    $scope_sql = '';
    if (column_exists('api_idempotency_keys', 'tenant_id')) {
        $scope_sql = ' AND tenant_id = ?';
        $params[] = $tenant_id;
    }
    $rows = db_fetch_all(
        "SELECT id, response_json FROM api_idempotency_keys WHERE response_json IS NOT NULL AND action <> 'agent-delete-ticket-permanently'{$scope_sql}",
        $params
    );
    foreach ($rows as $row) {
        $response = json_decode((string) ($row['response_json'] ?? ''), true);
        if (is_array($response) && ticket_permanent_delete_response_references_ticket($response, $ticket_id)) {
            db_delete('api_idempotency_keys', 'id = ?', [(int) $row['id']]);
        }
    }
}

function ticket_permanent_delete_related_records(int $ticket_id): void
{
    if (table_exists('ticket_message_attachments') && table_exists('ticket_messages')) {
        db_query(
            'DELETE FROM ticket_message_attachments WHERE ticket_message_id IN (SELECT id FROM ticket_messages WHERE ticket_id = ?)',
            [$ticket_id]
        );
    }
    foreach ([
        'notifications',
        'ticket_access',
        'ticket_shares',
        'activity_log',
        'ticket_history',
        'recurring_task_runs',
        'email_ingest_logs',
        'ticket_time_entries',
        'ticket_messages',
        'attachments',
        'comments',
    ] as $table) {
        if (table_exists($table) && column_exists($table, 'ticket_id')) {
            db_delete($table, 'ticket_id = ?', [$ticket_id]);
        }
    }
}

function ticket_permanent_delete(int $ticket_id, string $confirmation, array $actor, ?string $request_id = null): array
{
    if (!function_exists('can_permanently_delete_tickets') || !can_permanently_delete_tickets($actor)) {
        throw new RuntimeException('Forbidden', 403);
    }

    ticket_permanent_delete_ensure_tables();
    $preflight = ticket_permanent_delete_preflight($ticket_id);
    $tenant_id = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
    if (!$preflight) {
        $receipt = ticket_permanent_delete_receipt($ticket_id, $tenant_id);
        if ($receipt) {
            return [
                'deleted' => true,
                'already_deleted' => true,
                'deleted_ticket_id' => $ticket_id,
                'deleted_at' => $receipt['deleted_at'],
                'storage_cleanup_pending' => 0,
            ];
        }
        throw new RuntimeException('Ticket not found', 404);
    }

    $ticket = get_ticket($ticket_id);
    $tenant_id = ticket_permanent_delete_tenant_id($ticket ?: []);
    if (!hash_equals($preflight['ticket_code'], trim($confirmation))) {
        throw new InvalidArgumentException('Confirmation must exactly match the ticket code.', 422);
    }

    $attachments = db_fetch_all('SELECT * FROM attachments WHERE ticket_id = ? ORDER BY id', [$ticket_id]);
    $db = get_db();
    $owns_transaction = false;
    $outbox_ids = [];
    $request_id = $request_id ?: bin2hex(random_bytes(16));

    try {
        $owns_transaction = db_begin_owned($db);

        foreach ($attachments as $attachment) {
            $outbox_ids[] = (int) db_insert('ticket_storage_deletion_outbox', [
                'tenant_id' => $tenant_id,
                'ticket_id' => $ticket_id,
                'attachment_payload' => json_encode($attachment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        db_insert('ticket_deletion_receipts', [
            'tenant_id' => $tenant_id,
            'ticket_id' => $ticket_id,
            'ticket_code_hash' => hash('sha256', $preflight['ticket_code']),
            'deleted_by' => (int) $actor['id'],
            'request_id' => substr($request_id, 0, 64),
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        ticket_permanent_delete_remove_idempotency_references($ticket_id, $tenant_id);
        ticket_permanent_delete_related_records($ticket_id);
        $deleted = db_delete('tickets', 'id = ?', [$ticket_id]);
        if ($deleted !== 1) {
            throw new RuntimeException('Ticket deletion did not affect exactly one record.');
        }

        if (table_exists('report_snapshots')) {
            if (column_exists('report_snapshots', 'tenant_id')) {
                db_delete('report_snapshots', 'tenant_id = ?', [$tenant_id]);
            } elseif (table_exists('report_templates') && column_exists('report_templates', 'tenant_id')) {
                db_query('DELETE rs FROM report_snapshots rs INNER JOIN report_templates rt ON rt.id = rs.report_template_id WHERE rt.tenant_id = ?', [$tenant_id]);
            } else {
                // Single-instance schema: every cached snapshot may include the deleted ticket.
                db_delete('report_snapshots', '1 = 1');
            }
        }

        if (function_exists('log_security_event')) {
            log_security_event('ticket_permanently_deleted', (int) $actor['id'], '');
        }

        $cleanup = static function () use ($outbox_ids): void {
            ticket_permanent_delete_process_storage($outbox_ids);
        };
        if ($owns_transaction) {
            db_commit_owned($owns_transaction, $db);
            $cleanup();
        } else {
            ticket_permanent_delete_register_after_commit($cleanup);
        }
    } catch (Throwable $e) {
        db_rollback_owned($owns_transaction, $db);
        throw $e;
    }

    $pending = 0;
    if ($outbox_ids) {
        $pending = (int) (db_fetch_one(
            'SELECT COUNT(*) AS cnt FROM ticket_storage_deletion_outbox WHERE id IN (' . implode(',', array_fill(0, count($outbox_ids), '?')) . ') AND processed_at IS NULL',
            $outbox_ids
        )['cnt'] ?? 0);
    }

    return [
        'deleted' => true,
        'already_deleted' => false,
        'deleted_ticket_id' => $ticket_id,
        'deleted_at' => date('Y-m-d H:i:s'),
        'storage_cleanup_pending' => $pending,
    ];
}
