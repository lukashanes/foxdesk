<?php
$rows = [];
$ip = '192.0.2.10';
$broken = false;
class MailBudgetFixture {
    public bool $active = false;
    private array $snapshot = [];
    function inTransaction() { return $this->active; }
    function beginTransaction() { $this->snapshot = $GLOBALS['rows']; $this->active = true; }
    function commit() { $this->active = false; }
    function rollBack() { $GLOBALS['rows'] = $this->snapshot; $this->active = false; }
}
$db = new MailBudgetFixture();
function get_db() { return $GLOBALS['db']; }
function get_client_ip() { return $GLOBALS['ip']; }
function rate_limit_table_exists() { return true; }
function db_query($sql, $params) {
    if ($GLOBALS['broken']) { throw new RuntimeException('Offline failure'); }
    if (str_starts_with($sql, 'INSERT IGNORE')) {
        [$key, $start] = $params;
        $GLOBALS['rows'][$key] ??= ['id' => $key, 'attempts' => 0, 'window_start' => $start];
    } else {
        [$count, $start, $now, $key] = $params;
        $GLOBALS['rows'][$key] = ['id' => $key, 'attempts' => $count, 'window_start' => $start];
    }
}
function db_fetch_one($sql, $params) {
    if (!str_contains($sql, 'FOR UPDATE')) { throw new LogicException('Budget read must hold a row lock.'); }
    return $GLOBALS['rows'][$params[0]] ?? null;
}
require_once dirname(__DIR__) . '/includes/public-mail-rate-limit.php';
if (!public_auth_mail_reserve('Person@example.test')) { throw new RuntimeException('First request must pass.'); }
$snapshot = $rows;
$ip = '192.0.2.11';
if (public_auth_mail_reserve(' person@EXAMPLE.test ') || $rows !== $snapshot) { throw new RuntimeException('Cooldown must span signup/reset, IPs and normalized addresses without consuming other budgets.'); }
$cool = 'pubmail.cool:' . substr(hash('sha256', 'person@example.test'), 0, 32);
$rows[$cool]['window_start'] = date('Y-m-d H:i:s', time() - 61);
if (!public_auth_mail_reserve('person@example.test')) { throw new RuntimeException('Cooldown expiry must permit a legitimate retry.'); }
$rows['pubmail.day']['attempts'] = 100000;
if (public_auth_mail_reserve('other@example.test')) { throw new RuntimeException('Global ceiling was bypassed.'); }
$rows = [];
$ip = '192.0.2.12';
for ($i = 0; $i < 5; $i++) {
    if (!public_auth_mail_reserve('person' . $i . '@example.test')) { throw new RuntimeException('Ordinary requests unexpectedly denied.'); }
}
if (public_auth_mail_reserve('sixth@example.test')) { throw new RuntimeException('IP quota was bypassed.'); }
$rows = [];
$broken = true;
if (public_auth_mail_reserve('person@example.test') || $db->inTransaction()) { throw new RuntimeException('Budget storage failure must fail closed and release the transaction.'); }
foreach (['pages/forgot-password.php'] as $path) {
    $source = file_get_contents(dirname(__DIR__) . '/' . $path);
    $guard = strpos($source, 'public_auth_mail_reserve($email)');
    $mutation = strpos($source, '$token = generate_reset_token()');
    if ($mutation === false) { $mutation = strpos($source, '$user = db_fetch_one('); }
    if ($guard === false || $guard > $mutation) { throw new RuntimeException('Budget must precede token writes: ' . $path); }
}
echo "Public mail budget regression OK\n";
