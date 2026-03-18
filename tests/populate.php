<?php
/**
 * FoxDesk Test Data Population Script
 * Run inside Docker: docker exec foxdesk-web-1 php /var/www/html/tests/populate.php
 */

// Bootstrap — mimic index.php environment
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SESSION = [];
define('BASE_PATH', realpath(__DIR__ . '/..'));
define('APP_VERSION', '0.3.86');
require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/database.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/auth.php';

echo "=== FoxDesk Data Population ===\n\n";

// Helper
function pop_log($msg) { echo "  $msg\n"; }

// ─── Ensure notifications table exists ───
try {
    if (!table_exists('notifications')) {
        db_query("CREATE TABLE notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ticket_id INT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            title VARCHAR(255) NOT NULL,
            message TEXT,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_user (user_id),
            INDEX idx_notif_read (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        pop_log("Created notifications table");
    }
} catch (Exception $e) {
    pop_log("Notifications table: " . $e->getMessage());
}

// ─── Ensure ticket_access table exists ───
try {
    if (!table_exists('ticket_access')) {
        db_query("CREATE TABLE ticket_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ticket_user (ticket_id, user_id),
            INDEX idx_ta_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        pop_log("Created ticket_access table");
    }
} catch (Exception $e) {
    pop_log("ticket_access table: " . $e->getMessage());
}

// ─── 1. Organizations ───
echo "\n[Organizations]\n";
$orgs = [
    ['name' => 'Acme Corp', 'billable_rate' => 150.00, 'contact_email' => 'info@acme.test', 'address' => '123 Main St, New York'],
    ['name' => 'Blue Mountain Consulting', 'billable_rate' => 95.00, 'contact_email' => 'office@bluemtn.test', 'address' => '45 Oak Ave, Denver'],
    ['name' => 'Greenfield Nonprofit', 'billable_rate' => 0.00, 'contact_email' => 'hello@greenfield.test', 'address' => '8 Park Lane, Portland'],
];

$org_ids = [];
foreach ($orgs as $org) {
    $existing = db_fetch_one("SELECT id FROM organizations WHERE name = ?", [$org['name']]);
    if ($existing) {
        $org_ids[$org['name']] = $existing['id'];
        pop_log("Exists: {$org['name']} (#{$existing['id']})");
    } else {
        $id = db_insert('organizations', array_merge($org, [
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]));
        $org_ids[$org['name']] = $id;
        pop_log("Created: {$org['name']} (#{$id})");
    }
}

// ─── 2. Priorities ───
echo "\n[Priorities]\n";
$priorities_data = [
    ['name' => 'Low', 'slug' => 'low', 'color' => '#6b7280', 'sort_order' => 1, 'is_default' => 0],
    ['name' => 'Medium', 'slug' => 'medium', 'color' => '#f59e0b', 'sort_order' => 2, 'is_default' => 1],
    ['name' => 'High', 'slug' => 'high', 'color' => '#ef4444', 'sort_order' => 3, 'is_default' => 0],
    ['name' => 'Urgent', 'slug' => 'urgent', 'color' => '#dc2626', 'sort_order' => 4, 'is_default' => 0],
];

foreach ($priorities_data as $p) {
    $existing = db_fetch_one("SELECT id FROM priorities WHERE slug = ?", [$p['slug']]);
    if ($existing) {
        pop_log("Exists: {$p['name']}");
    } else {
        db_insert('priorities', $p);
        pop_log("Created: {$p['name']}");
    }
}
$priorities = db_fetch_all("SELECT * FROM priorities ORDER BY sort_order");
$priority_map = [];
foreach ($priorities as $p) $priority_map[$p['slug']] = $p['id'];

// ─── 3. Ticket Types ───
echo "\n[Ticket Types]\n";
$types_data = [
    ['name' => 'General', 'slug' => 'general', 'sort_order' => 1, 'is_default' => 1],
    ['name' => 'Bug Report', 'slug' => 'bug-report', 'sort_order' => 2, 'is_default' => 0],
    ['name' => 'Feature Request', 'slug' => 'feature-request', 'sort_order' => 3, 'is_default' => 0],
    ['name' => 'Quote', 'slug' => 'quote', 'sort_order' => 4, 'is_default' => 0],
];

if (table_exists('ticket_types')) {
    foreach ($types_data as $tt) {
        $existing = db_fetch_one("SELECT id FROM ticket_types WHERE slug = ?", [$tt['slug']]);
        if ($existing) {
            pop_log("Exists: {$tt['name']}");
        } else {
            db_insert('ticket_types', $tt);
            pop_log("Created: {$tt['name']}");
        }
    }
}
$type_map = [];
if (table_exists('ticket_types')) {
    $types = db_fetch_all("SELECT * FROM ticket_types ORDER BY sort_order");
    foreach ($types as $tt) $type_map[$tt['slug']] = $tt['id'];
}

// ─── 4. Statuses ───
echo "\n[Statuses]\n";
$statuses = db_fetch_all("SELECT * FROM statuses ORDER BY sort_order");
$status_map = [];
foreach ($statuses as $s) {
    $status_map[$s['slug'] ?? strtolower($s['name'])] = $s['id'];
    pop_log("Status: {$s['name']} (#{$s['id']})");
}

// ─── 5. Users ───
echo "\n[Users]\n";
$users_data = [
    ['email' => 'agent1@foxdesk.test', 'first_name' => 'Sarah', 'last_name' => 'Chen', 'role' => 'agent', 'cost_rate' => 45.00, 'org' => null],
    ['email' => 'agent2@foxdesk.test', 'first_name' => 'Marcus', 'last_name' => 'Webb', 'role' => 'agent', 'cost_rate' => 55.00, 'org' => null],
    ['email' => 'client1@foxdesk.test', 'first_name' => 'Tom', 'last_name' => 'Baker', 'role' => 'user', 'cost_rate' => 0, 'org' => 'Acme Corp'],
    ['email' => 'client2@foxdesk.test', 'first_name' => 'Lisa', 'last_name' => 'Rodriguez', 'role' => 'user', 'cost_rate' => 0, 'org' => 'Acme Corp'],
    ['email' => 'client3@foxdesk.test', 'first_name' => 'Hans', 'last_name' => 'Muller', 'role' => 'user', 'cost_rate' => 0, 'org' => 'Blue Mountain Consulting'],
    ['email' => 'client4@foxdesk.test', 'first_name' => 'Emily', 'last_name' => 'Chang', 'role' => 'user', 'cost_rate' => 0, 'org' => 'Greenfield Nonprofit'],
];

$user_ids = [];
// Get admin user
$admin = db_fetch_one("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$admin_id = $admin ? $admin['id'] : 1;
$user_ids['admin'] = $admin_id;

foreach ($users_data as $u) {
    $existing = db_fetch_one("SELECT id FROM users WHERE email = ?", [$u['email']]);
    if ($existing) {
        $user_ids[$u['email']] = $existing['id'];
        pop_log("Exists: {$u['first_name']} {$u['last_name']} (#{$existing['id']})");
    } else {
        $org_id = $u['org'] ? ($org_ids[$u['org']] ?? null) : null;
        $id = create_user($u['email'], 'test1234', $u['first_name'], $u['last_name'], $u['role']);
        if ($org_id || $u['cost_rate'] > 0) {
            $update = [];
            if ($org_id) $update['organization_id'] = $org_id;
            if ($u['cost_rate'] > 0 && column_exists('users', 'cost_rate')) $update['cost_rate'] = $u['cost_rate'];
            if (!empty($update)) {
                $sets = [];
                $vals = [];
                foreach ($update as $k => $v) {
                    $sets[] = "$k = ?";
                    $vals[] = $v;
                }
                $vals[] = $id;
                db_query("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
            }
        }
        $user_ids[$u['email']] = $id;
        pop_log("Created: {$u['first_name']} {$u['last_name']} (#{$id})");
    }
}

// Shorthand
$agent1 = $user_ids['agent1@foxdesk.test'];
$agent2 = $user_ids['agent2@foxdesk.test'];
$client1 = $user_ids['client1@foxdesk.test'];
$client2 = $user_ids['client2@foxdesk.test'];
$client3 = $user_ids['client3@foxdesk.test'];
$client4 = $user_ids['client4@foxdesk.test'];

// ─── 6. Tickets ───
echo "\n[Tickets]\n";

// Check how many test tickets already exist
$test_count = db_fetch_one("SELECT COUNT(*) as cnt FROM tickets WHERE title LIKE '[TEST]%'");
if (($test_count['cnt'] ?? 0) >= 15) {
    pop_log("Already have {$test_count['cnt']} test tickets, skipping creation");
    $tickets = db_fetch_all("SELECT id FROM tickets WHERE title LIKE '[TEST]%' ORDER BY id");
    $ticket_ids = array_column($tickets, 'id');
} else {

$now = time();
$tickets_data = [
    // New (6)
    ['title' => '[TEST] Login page shows error after password reset', 'desc' => 'After resetting my password, the login page shows "Invalid credentials" even though the new password works on retry.', 'user' => $client1, 'org' => 'Acme Corp', 'status' => 'new', 'priority' => 'high', 'assignee' => $agent1, 'tags' => 'bug, security', 'type' => 'bug-report', 'due' => date('Y-m-d', $now + 86400*2)],
    ['title' => '[TEST] Add CSV export for ticket list', 'desc' => 'We need to export our ticket data to CSV for monthly reporting.', 'user' => $client2, 'org' => 'Acme Corp', 'status' => 'new', 'priority' => 'medium', 'assignee' => null, 'tags' => 'feature, reporting', 'type' => 'feature-request', 'due' => date('Y-m-d', $now + 86400*14)],
    ['title' => '[TEST] Email notifications not arriving', 'desc' => 'We stopped receiving email notifications for new comments 2 days ago. SMTP settings unchanged.', 'user' => $client3, 'org' => 'Blue Mountain Consulting', 'status' => 'new', 'priority' => 'urgent', 'assignee' => $agent1, 'tags' => 'bug, email', 'type' => 'bug-report', 'due' => date('Y-m-d', $now - 86400)],
    ['title' => '[TEST] Request for API documentation', 'desc' => 'Is there documentation available for the ticket API? We want to integrate with our project management tool.', 'user' => $client1, 'org' => 'Acme Corp', 'status' => 'new', 'priority' => 'low', 'assignee' => null, 'tags' => 'api, documentation', 'type' => 'general', 'due' => null],
    ['title' => '[TEST] Dashboard loads slowly with many tickets', 'desc' => 'When we have 500+ tickets, the dashboard takes 8 seconds to load. Can this be optimized?', 'user' => $client2, 'org' => 'Acme Corp', 'status' => 'new', 'priority' => 'medium', 'assignee' => $agent2, 'tags' => 'performance', 'type' => 'bug-report', 'due' => date('Y-m-d', $now + 86400*7)],
    ['title' => '[TEST] Mobile responsive issues on ticket detail', 'desc' => 'The sidebar overlaps the main content on iPad and mobile devices.', 'user' => $client4, 'org' => 'Greenfield Nonprofit', 'status' => 'new', 'priority' => 'medium', 'assignee' => null, 'tags' => 'bug, ui', 'type' => 'bug-report', 'due' => null],

    // In Progress (4)
    ['title' => '[TEST] Implement bulk status change', 'desc' => 'Allow selecting multiple tickets and changing their status in one action.', 'user' => $admin_id, 'org' => null, 'status' => 'in-progress', 'priority' => 'high', 'assignee' => $agent1, 'tags' => 'feature, ui', 'type' => 'feature-request', 'due' => date('Y-m-d', $now + 86400*5)],
    ['title' => '[TEST] Fix time tracking rounding bug', 'desc' => 'Time entries show 0.00 hours when duration is less than 15 minutes due to rounding.', 'user' => $client3, 'org' => 'Blue Mountain Consulting', 'status' => 'in-progress', 'priority' => 'high', 'assignee' => $agent2, 'tags' => 'bug, time-tracking', 'type' => 'bug-report', 'due' => date('Y-m-d', $now + 86400*3)],
    ['title' => '[TEST] Add tag autocomplete to ticket form', 'desc' => 'When typing tags, suggest existing tags from the system for consistency.', 'user' => $admin_id, 'org' => null, 'status' => 'in-progress', 'priority' => 'medium', 'assignee' => $agent1, 'tags' => 'feature, tags', 'type' => 'feature-request', 'due' => date('Y-m-d', $now + 86400*10)],
    ['title' => '[TEST] Set up recurring weekly report', 'desc' => 'Configure automated weekly summary report sent to management every Monday.', 'user' => $client1, 'org' => 'Acme Corp', 'status' => 'in-progress', 'priority' => 'medium', 'assignee' => $agent2, 'tags' => 'reporting, automation', 'type' => 'general', 'due' => date('Y-m-d', $now - 86400*2)],

    // Testing (3)
    ['title' => '[TEST] Ticket sharing via public link', 'desc' => 'Generate a unique URL that allows external users to view a ticket read-only.', 'user' => $admin_id, 'org' => null, 'status' => 'testing', 'priority' => 'medium', 'assignee' => $agent1, 'tags' => 'feature, sharing', 'type' => 'feature-request', 'due' => date('Y-m-d', $now + 86400)],
    ['title' => '[TEST] Fix notification count not updating', 'desc' => 'The bell icon count stays at 0 even when there are unread notifications.', 'user' => $client2, 'org' => 'Acme Corp', 'status' => 'testing', 'priority' => 'high', 'assignee' => $agent2, 'tags' => 'bug, notifications', 'type' => 'bug-report', 'due' => date('Y-m-d', $now)],
    ['title' => '[TEST] Organization billing rate inheritance', 'desc' => 'Time entries should auto-fill billing rate from the ticket organization.', 'user' => $admin_id, 'org' => null, 'status' => 'testing', 'priority' => 'medium', 'assignee' => $agent1, 'tags' => 'feature, billing', 'type' => 'feature-request', 'due' => null],

    // Waiting (3)
    ['title' => '[TEST] Migrate old tickets from Zendesk', 'desc' => 'Client needs to import 2000 tickets from Zendesk CSV export. Waiting for data file.', 'user' => $client1, 'org' => 'Acme Corp', 'status' => 'waiting', 'priority' => 'low', 'assignee' => $agent1, 'tags' => 'migration', 'type' => 'general', 'due' => date('Y-m-d', $now + 86400*30)],
    ['title' => '[TEST] Custom fields for ticket form', 'desc' => 'Need to add custom dropdown fields per organization. Waiting for spec from product.', 'user' => $admin_id, 'org' => null, 'status' => 'waiting', 'priority' => 'medium', 'assignee' => $agent2, 'tags' => 'feature, custom-fields', 'type' => 'feature-request', 'due' => null],
    ['title' => '[TEST] SSL certificate renewal', 'desc' => 'SSL cert expires in 2 weeks. Waiting for IT to provide new cert files.', 'user' => $client3, 'org' => 'Blue Mountain Consulting', 'status' => 'waiting', 'priority' => 'urgent', 'assignee' => $agent1, 'tags' => 'security, infrastructure', 'type' => 'general', 'due' => date('Y-m-d', $now + 86400*14)],

    // Done (2)
    ['title' => '[TEST] Set up two-factor authentication', 'desc' => 'Implement TOTP-based 2FA for admin and agent accounts.', 'user' => $admin_id, 'org' => null, 'status' => 'done', 'priority' => 'high', 'assignee' => $agent1, 'tags' => 'feature, security', 'type' => 'feature-request', 'due' => date('Y-m-d', $now - 86400*5)],
    ['title' => '[TEST] Fix duplicate comment submissions', 'desc' => 'Double-clicking the submit button creates two identical comments.', 'user' => $client4, 'org' => 'Greenfield Nonprofit', 'status' => 'done', 'priority' => 'medium', 'assignee' => $agent2, 'tags' => 'bug, ui', 'type' => 'bug-report', 'due' => date('Y-m-d', $now - 86400*3)],
];

// Resolve status slugs
$status_slug_map = [];
foreach ($statuses as $s) {
    $slug = $s['slug'] ?? strtolower(str_replace(' ', '-', $s['name']));
    $status_slug_map[$slug] = $s['id'];
}
// Common aliases
if (!isset($status_slug_map['new']) && isset($status_slug_map['open'])) $status_slug_map['new'] = $status_slug_map['open'];
if (!isset($status_slug_map['done']) && isset($status_slug_map['closed'])) $status_slug_map['done'] = $status_slug_map['closed'];
if (!isset($status_slug_map['in-progress'])) {
    // Try to find it
    foreach ($statuses as $s) {
        $n = strtolower($s['name']);
        if (strpos($n, 'progress') !== false) { $status_slug_map['in-progress'] = $s['id']; break; }
    }
}
if (!isset($status_slug_map['testing'])) {
    foreach ($statuses as $s) {
        $n = strtolower($s['name']);
        if (strpos($n, 'test') !== false) { $status_slug_map['testing'] = $s['id']; break; }
    }
}
if (!isset($status_slug_map['waiting'])) {
    foreach ($statuses as $s) {
        $n = strtolower($s['name']);
        if (strpos($n, 'wait') !== false || strpos($n, 'pending') !== false) { $status_slug_map['waiting'] = $s['id']; break; }
    }
}

// Default status fallback
$default_status = db_fetch_one("SELECT id FROM statuses WHERE is_default = 1 LIMIT 1");
$default_status_id = $default_status ? $default_status['id'] : 1;

$ticket_ids = [];
foreach ($tickets_data as $i => $td) {
    $status_id = $status_slug_map[$td['status']] ?? $default_status_id;
    $priority_id = $priority_map[$td['priority']] ?? null;
    $org_id = $td['org'] ? ($org_ids[$td['org']] ?? null) : null;
    $type_id = !empty($td['type']) && isset($type_map[$td['type']]) ? $type_map[$td['type']] : null;

    $data = [
        'title' => $td['title'],
        'description' => $td['desc'],
        'user_id' => $td['user'],
        'status_id' => $status_id,
    ];
    if ($priority_id) $data['priority_id'] = $priority_id;
    if ($org_id) $data['organization_id'] = $org_id;
    if ($td['assignee']) $data['assignee_id'] = $td['assignee'];
    if ($td['due']) $data['due_date'] = $td['due'];
    if ($td['tags']) $data['tags'] = $td['tags'];
    if ($type_id && column_exists('tickets', 'ticket_type_id')) $data['ticket_type_id'] = $type_id;

    $tid = create_ticket($data);
    $ticket_ids[] = $tid;

    // Backdate some tickets
    $days_ago = max(0, 20 - $i);
    $created = date('Y-m-d H:i:s', $now - 86400 * $days_ago - rand(0, 43200));
    db_query("UPDATE tickets SET created_at = ?, updated_at = ? WHERE id = ?", [$created, $created, $tid]);

    pop_log("Created: #{$tid} — " . substr($td['title'], 7, 50));
}
} // end skip check

// ─── 7. Comments ───
echo "\n[Comments]\n";
if (function_exists('add_comment')) {
    $comment_count = 0;
    $comments = [
        // Ticket 0 (Login page error)
        [$ticket_ids[0] ?? 0, $agent1, "I can reproduce this issue. It seems the session token isn't being cleared properly during the reset flow.", 0],
        [$ticket_ids[0] ?? 0, $client1, "Thanks Sarah. Should I try clearing cookies as a workaround?", 0],
        [$ticket_ids[0] ?? 0, $agent1, "Yes, clearing cookies works as a temp fix. I'm working on the root cause.", 0],
        [$ticket_ids[0] ?? 0, $agent1, "Found it — the old session cookie conflicts with the new one. Deploying fix today.", 1],

        // Ticket 2 (Email notifications)
        [$ticket_ids[2] ?? 0, $agent1, "Checking SMTP logs now. Can you confirm the last notification you received?", 0],
        [$ticket_ids[2] ?? 0, $client3, "The last email came through on Monday around 3pm for ticket #1042.", 0],
        [$ticket_ids[2] ?? 0, $agent1, "SMTP server is returning 550 errors — looks like our sending domain was blocklisted. Escalating to IT.", 1],

        // Ticket 4 (Dashboard slow)
        [$ticket_ids[4] ?? 0, $agent2, "Running query analysis. The dashboard queries aren't using indexes on the tickets table.", 1],
        [$ticket_ids[4] ?? 0, $agent2, "Added composite index on (status_id, created_at). Load time down from 8s to 1.2s in staging.", 1],

        // Ticket 6 (Bulk status change)
        [$ticket_ids[6] ?? 0, $agent1, "Started implementation. The checkbox UI is done, working on the backend batch update endpoint.", 0],
        [$ticket_ids[6] ?? 0, $admin_id, "Make sure we log each individual status change in the activity log, not just a batch entry.", 0],
        [$ticket_ids[6] ?? 0, $agent1, "Good point. Updated the implementation to log per-ticket. Also adding undo support.", 0],

        // Ticket 7 (Time tracking rounding)
        [$ticket_ids[7] ?? 0, $client3, "This is causing billing discrepancies. A 10-minute call shows as 0.00 hours on the invoice.", 0],
        [$ticket_ids[7] ?? 0, $agent2, "Confirmed — the rounding function uses floor() instead of ceil(). Fixing to round up to nearest minute.", 0],
        [$ticket_ids[7] ?? 0, $agent2, "Fix deployed. Now showing actual minutes. 10-min entry shows as 0.17 hours correctly.", 0],

        // Ticket 8 (Tag autocomplete)
        [$ticket_ids[8] ?? 0, $agent1, "Built the autocomplete component. It queries existing tags via a new API endpoint.", 0],
        [$ticket_ids[8] ?? 0, $agent1, "Supports fuzzy matching and shows tag usage count. Ready for review.", 1],

        // Ticket 9 (Weekly report)
        [$ticket_ids[9] ?? 0, $agent2, "Set up the recurring task. Report includes: open tickets, closed this week, SLA breaches, time logged.", 0],
        [$ticket_ids[9] ?? 0, $client1, "Can you add a breakdown by priority? We need to see how many urgent tickets were opened.", 0],
        [$ticket_ids[9] ?? 0, $agent2, "Added priority breakdown and trend chart. Sending test report for your review.", 0],

        // Ticket 10 (Ticket sharing)
        [$ticket_ids[10] ?? 0, $agent1, "Share links generate a unique hash URL. Read-only view strips internal comments.", 0],
        [$ticket_ids[10] ?? 0, $admin_id, "Add an expiration option — 24h, 7d, 30d, or never.", 0],
        [$ticket_ids[10] ?? 0, $agent1, "Expiration added. Default is 7 days. Testing now.", 0],

        // Ticket 11 (Notification count)
        [$ticket_ids[11] ?? 0, $agent2, "The issue was in the polling interval — it only checked on page load, not periodically.", 0],
        [$ticket_ids[11] ?? 0, $agent2, "Added 60-second polling for notification count. Also fires on comment/status change events.", 0],

        // Ticket 17 (2FA)
        [$ticket_ids[17] ?? 0, $agent1, "TOTP implementation complete. Using SHA-256 with 6-digit codes, 30-second interval.", 0],
        [$ticket_ids[17] ?? 0, $admin_id, "Tested with Google Authenticator and Authy. Both work. Deploying to production.", 0],
        [$ticket_ids[17] ?? 0, $agent1, "Deployed. Recovery codes generated during setup. Docs updated.", 0],

        // Ticket 18 (Duplicate comments)
        [$ticket_ids[18] ?? 0, $client4, "This happened to me 3 times today. Very confusing when the duplicate shows up.", 0],
        [$ticket_ids[18] ?? 0, $agent2, "Added debounce on the submit button + server-side duplicate detection (same content within 5 seconds).", 0],
        [$ticket_ids[18] ?? 0, $agent2, "Fix verified in production. No more duplicates.", 0],
    ];

    foreach ($comments as $c) {
        if ($c[0] <= 0) continue;
        try {
            add_comment($c[0], $c[1], $c[2], $c[3]);
            $comment_count++;
        } catch (Exception $e) {
            pop_log("Comment error: " . $e->getMessage());
        }
    }
    pop_log("Created {$comment_count} comments");
} else {
    pop_log("add_comment() not available, skipping");
}

// ─── 8. Time Entries ───
echo "\n[Time Entries]\n";
if (function_exists('add_manual_time_entry')) {
    $time_count = 0;
    $time_entries = [
        // ticket_index, user_id, minutes, summary
        [0, $agent1, 45, 'Investigating session token issue'],
        [0, $agent1, 30, 'Deploying fix and testing'],
        [2, $agent1, 60, 'SMTP log analysis and troubleshooting'],
        [2, $agent1, 20, 'Escalation call with IT team'],
        [4, $agent2, 90, 'Database query optimization'],
        [4, $agent2, 30, 'Index deployment and verification'],
        [6, $agent1, 120, 'Bulk status change implementation'],
        [6, $agent1, 45, 'Undo support and activity logging'],
        [7, $agent2, 60, 'Rounding bug analysis and fix'],
        [8, $agent1, 180, 'Tag autocomplete component development'],
        [8, $agent1, 45, 'API endpoint for tag search'],
        [9, $agent2, 90, 'Report template setup and scheduling'],
        [10, $agent1, 150, 'Share link feature development'],
        [17, $agent1, 240, 'TOTP 2FA implementation'],
        [18, $agent2, 35, 'Debounce fix for duplicate comments'],
    ];

    foreach ($time_entries as $te) {
        $tid = $ticket_ids[$te[0]] ?? 0;
        if ($tid <= 0) continue;
        try {
            $started = date('Y-m-d H:i:s', time() - rand(86400, 86400*15) - $te[2]*60);
            $ended = date('Y-m-d H:i:s', strtotime($started) + $te[2]*60);
            add_manual_time_entry($tid, $te[1], [
                'started_at' => $started,
                'ended_at' => $ended,
                'duration_minutes' => $te[2],
                'summary' => $te[3],
                'is_billable' => 1,
                'source' => 'manual',
            ]);
            $time_count++;
        } catch (Exception $e) {
            pop_log("Time entry error: " . $e->getMessage());
        }
    }
    pop_log("Created {$time_count} time entries");
} else {
    pop_log("add_manual_time_entry() not available, skipping");
}

// ─── 9. Notifications ───
echo "\n[Notifications]\n";
if (table_exists('notifications')) {
    $notif_data = [
        [$agent1, $ticket_ids[0] ?? 0, 'assigned_to_you', 'Ticket assigned', 'Login page error assigned to you'],
        [$agent1, $ticket_ids[2] ?? 0, 'assigned_to_you', 'Ticket assigned', 'Email notifications issue assigned to you'],
        [$agent2, $ticket_ids[4] ?? 0, 'new_comment', 'New comment', 'Marcus commented on Dashboard performance'],
        [$agent1, $ticket_ids[6] ?? 0, 'new_comment', 'New comment', 'Admin commented on Bulk status change'],
        [$admin_id, $ticket_ids[7] ?? 0, 'status_changed', 'Status changed', 'Time tracking bug moved to In Progress'],
        [$client1, $ticket_ids[0] ?? 0, 'new_comment', 'New comment', 'Sarah replied to your ticket'],
        [$client3, $ticket_ids[2] ?? 0, 'new_comment', 'New comment', 'Sarah replied about email issue'],
    ];
    $notif_count = 0;
    foreach ($notif_data as $n) {
        if ($n[1] <= 0) continue;
        try {
            db_insert('notifications', [
                'user_id' => $n[0],
                'ticket_id' => $n[1],
                'type' => $n[2],
                'title' => $n[3],
                'message' => $n[4],
                'is_read' => rand(0, 1),
                'created_at' => date('Y-m-d H:i:s', time() - rand(0, 86400*10)),
            ]);
            $notif_count++;
        } catch (Exception $e) {
            pop_log("Notification error: " . $e->getMessage());
        }
    }
    pop_log("Created {$notif_count} notifications");
}

// ─── 10. API Token ───
echo "\n[API Token]\n";
if (table_exists('api_tokens')) {
    $raw_token = 'fd_test_api_token_2024_abcdef';
    $token_hash = hash('sha256', $raw_token);
    $token_prefix = substr($raw_token, 0, 8);
    $existing_token = db_fetch_one("SELECT id FROM api_tokens WHERE token_hash = ?", [$token_hash]);
    if ($existing_token) {
        pop_log("API token already exists");
    } else {
        try {
            db_insert('api_tokens', [
                'user_id' => $agent1,
                'token_hash' => $token_hash,
                'token_prefix' => $token_prefix,
                'name' => 'Test API Token',
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            pop_log("Created test API token for agent1");
            pop_log("Raw token: $raw_token");
        } catch (Exception $e) {
            pop_log("API token error: " . $e->getMessage());
        }
    }
}

echo "\n=== Population Complete ===\n";
echo "Organizations: " . count($org_ids) . "\n";
echo "Users: " . count($user_ids) . "\n";
echo "Tickets: " . count($ticket_ids) . "\n";
echo "Use admin@foxdesk.test / test1234 to log in\n";
echo "Agent API: Bearer test-api-token-foxdesk-2024\n";
