<?php

const FOXDESK_TICKET_UNDO_SECONDS = 10;

function ticket_undo_require_schema(): void
{
    schema_require('ticket deletion undo', ['pending_deletions'], [
        'pending_deletions' => [
            'tenant_id', 'user_id', 'ticket_id', 'resource_type', 'resource_id',
            'token_hash', 'payload_json', 'expires_at', 'created_at',
        ],
    ]);
}

function ticket_undo_stage(string $type, int $resource_id, int $ticket_id, int $user_id, array $payload): string
{
    ticket_undo_require_schema();
    if (!in_array($type, ['comment', 'time_entry', 'attachment'], true)) {
        throw new InvalidArgumentException('Unsupported undo resource type.');
    }

    $token = bin2hex(random_bytes(24));
    db_insert('pending_deletions', [
        'tenant_id' => function_exists('current_tenant_id') ? current_tenant_id() : 0,
        'user_id' => $user_id,
        'ticket_id' => $ticket_id,
        'resource_type' => $type,
        'resource_id' => $resource_id,
        'token_hash' => hash('sha256', $token),
        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'expires_at' => date('Y-m-d H:i:s', time() + FOXDESK_TICKET_UNDO_SECONDS),
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    return $token;
}

function ticket_undo_find(string $type, string $token): ?array
{
    ticket_undo_require_schema();
    if ($token === '') {
        return null;
    }

    $params = [$type, hash('sha256', $token)];
    $tenantSql = function_exists('tenant_sql_filter') ? tenant_sql_filter('pending_deletions', '', $params) : '';
    $row = db_fetch_one(
        "SELECT * FROM pending_deletions
         WHERE resource_type = ? AND token_hash = ?{$tenantSql}
         LIMIT 1",
        $params
    );
    if (!$row || strtotime((string) ($row['expires_at'] ?? '')) < time()) {
        return null;
    }

    try {
        $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return null;
    }
    if (!is_array($payload)) {
        return null;
    }

    $row['payload'] = $payload;
    return $row;
}

function ticket_undo_forget(int $pending_id): void
{
    if ($pending_id > 0) {
        db_delete('pending_deletions', 'id = ?', [$pending_id]);
    }
}

function ticket_undo_finalize_row(array $row): bool
{
    $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
    if (($row['resource_type'] ?? '') === 'attachment' && is_array($payload)) {
        $attachment = $payload['attachment'] ?? null;
        if (is_array($attachment) && function_exists('delete_attachment_storage')) {
            if (!delete_attachment_storage($attachment)) {
                return false;
            }
        }
    }

    ticket_undo_forget((int) ($row['id'] ?? 0));
    return true;
}

function ticket_undo_finalize_expired(int $limit = 25): int
{
    if (!function_exists('table_exists') || !table_exists('pending_deletions')) {
        return 0;
    }

    $limit = max(1, min(100, $limit));
    // expires_at is written with PHP date(); compare with the same clock, not NOW().
    $params = [foxdesk_db_datetime()];
    $tenantSql = function_exists('tenant_sql_filter') ? tenant_sql_filter('pending_deletions', '', $params) : '';
    $rows = db_fetch_all(
        "SELECT * FROM pending_deletions
         WHERE expires_at <= ?{$tenantSql}
         ORDER BY id ASC LIMIT {$limit}",
        $params
    );

    $finalized = 0;
    foreach ($rows as $row) {
        try {
            if (ticket_undo_finalize_row($row)) {
                $finalized++;
            }
        } catch (Throwable $e) {
            error_log('Deferred deletion cleanup failed for pending deletion ' . (int) ($row['id'] ?? 0));
        }
    }
    return $finalized;
}
