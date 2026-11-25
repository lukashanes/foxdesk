<?php
/** Shared, atomic budgets for unauthenticated password-reset mail. */
function public_auth_mail_reserve(string $email): bool
{
    $email = strtolower(trim($email));
    $ip = get_client_ip();
    if ($email === '' || $ip === '') {
        return false;
    }
    if (!rate_limit_table_exists()) {
        error_log('Public authentication mail unavailable: rate_limits table is missing.');
        return false;
    }
    $recipient = substr(hash('sha256', $email), 0, 32);
    $ip_hash = substr(hash('sha256', $ip), 0, 32);
    $configured_limit = getenv('FOXDESK_PUBLIC_MAIL_DAILY_LIMIT');
    $daily_limit = $configured_limit === false || $configured_limit === '' ? 1000 : (int) $configured_limit;
    $budgets = [
        'pubmail.ip:' . $ip_hash => [5, 900],
        'pubmail.to:' . $recipient => [5, 900],
        'pubmail.dayto:' . $recipient => [10, 86400],
        'pubmail.cool:' . $recipient => [1, 60],
        'pubmail.day' => [max(1, min(100000, $daily_limit)), 86400],
    ];
    // All requests lock shared rows in the same order. The sentinel separates
    // global recipient/IP counters from the old per-client-IP limiter rows.
    ksort($budgets);
    $db = get_db();
    if ($db->inTransaction()) {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    try {
        $db->beginTransaction();
        $updates = [];
        foreach ($budgets as $key => [$limit, $window]) {
            db_query("INSERT IGNORE INTO rate_limits (rate_key, ip_address, attempts, window_start, updated_at) VALUES (?, '0.0.0.0', 0, ?, ?)", [$key, $now, $now]);
            $row = db_fetch_one("SELECT id, attempts, window_start FROM rate_limits WHERE rate_key = ? AND ip_address = '0.0.0.0' FOR UPDATE", [$key]);
            if (!$row) {
                throw new RuntimeException('Public mail budget is unavailable.');
            }
            $start = strtotime($row['window_start']);
            $expired = $start === false || time() - $start >= $window;
            $attempts = $expired ? 0 : (int) $row['attempts'];
            if ($attempts >= $limit) {
                $db->rollBack();
                return false;
            }
            $updates[] = [$row['id'], $attempts + 1, $expired ? $now : $row['window_start']];
        }
        foreach ($updates as [$id, $attempts, $start]) {
            db_query('UPDATE rate_limits SET attempts = ?, window_start = ?, updated_at = ? WHERE id = ?', [$attempts, $start, $now, $id]);
        }
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Public authentication mail unavailable: budget storage failed.');
        return false;
    }
}
