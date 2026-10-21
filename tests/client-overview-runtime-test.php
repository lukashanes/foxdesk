<?php
// Real SQL over synthetic data; no application database or customer data is used.
$dsn = getenv('FOXDESK_TEST_DSN') ?: 'sqlite::memory:';
if ($dsn !== 'sqlite::memory:' && getenv('FOXDESK_LOCAL_TESTS') !== '1') {
    fwrite(STDERR, "An external fixture database requires FOXDESK_LOCAL_TESTS=1. Use a disposable database.\n");
    exit(2);
}
$db = new PDO($dsn, getenv('FOXDESK_TEST_DB_USER') ?: null, getenv('FOXDESK_TEST_DB_PASS') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE tickets (id INTEGER, organization_id INTEGER, user_id INTEGER, status_id INTEGER, is_archived INTEGER)');
$db->exec('CREATE TABLE users (id INTEGER, first_name TEXT, last_name TEXT, email TEXT, role TEXT, is_active INTEGER, organization_id INTEGER, permissions TEXT, avatar TEXT)');
$db->beginTransaction();
$insert = $db->prepare('INSERT INTO tickets VALUES (?, ?, ?, ?, ?)');
for ($i = 1; $i <= 12000; $i++) $insert->execute([$i, $i % 3 === 0 ? 8 : 7, $i % 2 + 1, $i % 5, $i % 7 === 0 ? 1 : 0]);
$insert = $db->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)');
for ($i = 1; $i <= 500; $i++) {
    $permissions = match ($i % 5) { 0 => '{"organization_ids":[7,"8",7,-1]}', 1 => '{"organization_id":"7"}', 2 => 'invalid', 3 => 'null', default => '{}' };
    $insert->execute([$i, 'Test', (string) $i, $i % 13 === 0 ? "deleted-user-$i@invalid.local" : "u$i@example.test", $i % 2 ? 'user' : 'agent', $i % 3 ? 1 : 0, $i % 4 ? 8 : 7, $permissions]);
}
$db->commit();
$queries = 0; $fetched = 0; $actor = ['id' => 1, 'role' => 'admin'];
function current_user() { return $GLOBALS['actor']; }
function db_fetch_all($sql, $params = []) { $GLOBALS['queries']++; $s = $GLOBALS['db']->prepare($sql); $s->execute($params); $rows = $s->fetchAll(); $GLOBALS['fetched'] += count($rows); return $rows; }
function get_user($id) {
    static $cache = [];
    if (!array_key_exists($id, $cache)) $cache[$id] = db_fetch_all('SELECT * FROM users WHERE id = ?', [$id])[0] ?? false;
    return $cache[$id];
}
function get_status($id) { return ['status_group' => ['new', 'active', 'waiting', 'done', 'archived'][$id % 5]]; }
function build_ticket_visibility_filters_for_user($user, $filters) { return $filters + ['owner' => ($user['role'] ?? '') === 'admin' ? null : $user['id']]; }
function build_ticket_where_clause($filters, &$params) {
    $params = [$filters['organization_id']];
    $where = ' WHERE t.organization_id = ?';
    if ($filters['owner'] !== null) { $where .= ' AND t.user_id = ?'; $params[] = $filters['owner']; }
    return $where;
}
require dirname(__DIR__) . '/includes/user-functions.php';
require dirname(__DIR__) . '/includes/modules/tickets/ticket-status-groups.php';
require dirname(__DIR__) . '/includes/modules/clients/client-overview.php';
$failures = [];
function verify_client($ok, $message) { if (!$ok) $GLOBALS['failures'][] = $message; }
foreach ([['id' => 1, 'role' => 'admin'], ['id' => 2, 'role' => 'agent']] as $actor) {
    $params = []; $where = build_ticket_where_clause(build_ticket_visibility_filters_for_user($actor, ['organization_id' => 7]), $params);
    $expected = ['open' => 0, 'waiting' => 0, 'done' => 0, 'archived' => 0, 'all' => 0];
    foreach (db_fetch_all('SELECT * FROM tickets t' . $where, $params) as $row) {
        $group = client_overview_ticket_group($row); $expected['all']++;
        if (in_array($group, ['done', 'archived'], true)) $expected[$group]++;
        else { $expected['open']++; if ($group === 'waiting') $expected['waiting']++; }
    }
    $queries = $fetched = 0; $start = hrtime(true);
    $actual = client_overview_ticket_counts(7);
    printf("counts role=%s queries=%d fetched=%d ms=%.3f\n", $actor['role'], $queries, $fetched, (hrtime(true) - $start) / 1e6);
    verify_client($actual === $expected, 'Ticket totals changed for ' . $actor['role']);
    verify_client($fetched <= 10 && $queries === 1, 'Counts must fetch status groups, not every ticket.');
}
$all = db_fetch_all("SELECT id, first_name, last_name, email, role, is_active, organization_id, permissions, avatar FROM users WHERE email NOT LIKE 'deleted-user-%@invalid.local' ORDER BY role = 'user' DESC, last_name, first_name");
$expected = array_values(array_filter($all, static function ($user) {
    $p = json_decode($user['permissions'], true); $ids = is_array($p) ? get_permissions_organization_ids($p) : [];
    $ids[] = (int) $user['organization_id']; return in_array(7, $ids, true);
}));
$queries = $fetched = 0; $start = hrtime(true);
$actual = client_overview_contacts(7);
printf("contacts queries=%d fetched=%d ms=%.3f\n", $queries, $fetched, (hrtime(true) - $start) / 1e6);
verify_client($actual === $expected, 'Contact rows, order, primary/secondary membership or inactive users changed.');
verify_client($queries === 1, 'Contacts must not re-fetch every user.');
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Client overview runtime and query budgets passed\n";
