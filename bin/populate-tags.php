<?php
/**
 * Populate tickets with realistic tags.
 * Usage: php bin/populate-tags.php
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/database.php';

$pdo = get_db();

// 1. Ensure tags column exists
$col = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'tags'")->fetch();
if (!$col) {
    $pdo->exec("ALTER TABLE tickets ADD COLUMN tags TEXT DEFAULT NULL");
    echo "✓ Created 'tags' column on tickets table.\n";
} else {
    echo "✓ Tags column already exists.\n";
}

// 2. Get all tickets
$tickets = $pdo->query("SELECT id, title FROM tickets ORDER BY id")->fetchAll();
echo "Found " . count($tickets) . " tickets.\n";

if (empty($tickets)) {
    echo "No tickets to tag.\n";
    exit(0);
}

// 3. Define tag pool — realistic helpdesk tags
$tag_pool = [
    'bug', 'feature-request', 'urgent', 'billing',
    'login-issue', 'UI', 'backend', 'API',
    'performance', 'security', 'onboarding',
    'documentation', 'mobile', 'email', 'integration',
    'database', 'permissions', 'reports', 'dashboard',
    'networking', 'hardware', 'VPN', 'SSO',
    'customer-feedback', 'enhancement', 'internal',
];

// 4. Assign tags to each ticket based on title keywords + some randomness
$keyword_tag_map = [
    'bug'           => ['bug'],
    'error'         => ['bug'],
    'fix'           => ['bug'],
    'crash'         => ['bug', 'urgent'],
    'broken'        => ['bug'],
    'feature'       => ['feature-request'],
    'request'       => ['feature-request'],
    'add'           => ['feature-request', 'enhancement'],
    'new'           => ['feature-request'],
    'login'         => ['login-issue', 'security'],
    'password'      => ['login-issue', 'security'],
    'auth'          => ['login-issue', 'SSO'],
    'sso'           => ['SSO', 'security'],
    'slow'          => ['performance'],
    'performance'   => ['performance'],
    'speed'         => ['performance'],
    'timeout'       => ['performance', 'backend'],
    'bill'          => ['billing'],
    'invoice'       => ['billing'],
    'payment'       => ['billing'],
    'subscription'  => ['billing'],
    'ui'            => ['UI'],
    'design'        => ['UI'],
    'layout'        => ['UI', 'dashboard'],
    'button'        => ['UI'],
    'page'          => ['UI'],
    'mobile'        => ['mobile'],
    'phone'         => ['mobile'],
    'app'           => ['mobile'],
    'api'           => ['API', 'backend'],
    'endpoint'      => ['API', 'backend'],
    'webhook'       => ['API', 'integration'],
    'email'         => ['email'],
    'notification'  => ['email'],
    'smtp'          => ['email', 'backend'],
    'report'        => ['reports'],
    'export'        => ['reports'],
    'csv'           => ['reports'],
    'dashboard'     => ['dashboard'],
    'widget'        => ['dashboard', 'UI'],
    'database'      => ['database', 'backend'],
    'sql'           => ['database', 'backend'],
    'migration'     => ['database'],
    'permission'    => ['permissions', 'security'],
    'access'        => ['permissions', 'security'],
    'role'          => ['permissions'],
    'vpn'           => ['VPN', 'networking'],
    'network'       => ['networking'],
    'connect'       => ['networking'],
    'printer'       => ['hardware'],
    'hardware'      => ['hardware'],
    'setup'         => ['onboarding'],
    'onboard'       => ['onboarding'],
    'welcome'       => ['onboarding'],
    'doc'           => ['documentation'],
    'help'          => ['documentation'],
    'guide'         => ['documentation'],
    'integrate'     => ['integration'],
    'plugin'        => ['integration'],
    'sync'          => ['integration', 'backend'],
    'security'      => ['security'],
    'urgent'        => ['urgent'],
    'asap'          => ['urgent'],
    'critical'      => ['urgent'],
    'feedback'      => ['customer-feedback'],
    'suggestion'    => ['customer-feedback', 'enhancement'],
    'internal'      => ['internal'],
    'team'          => ['internal'],
    'update'        => ['enhancement'],
    'improve'       => ['enhancement'],
];

$updated = 0;
$stmt = $pdo->prepare("UPDATE tickets SET tags = ? WHERE id = ?");

foreach ($tickets as $ticket) {
    $title_lower = mb_strtolower($ticket['title'], 'UTF-8');
    $matched_tags = [];

    // Match based on title keywords
    foreach ($keyword_tag_map as $keyword => $ktags) {
        if (strpos($title_lower, $keyword) !== false) {
            $matched_tags = array_merge($matched_tags, $ktags);
        }
    }

    // If no keyword matches, assign 1-2 random tags
    if (empty($matched_tags)) {
        $random_count = rand(1, 2);
        $random_keys = array_rand($tag_pool, min($random_count, count($tag_pool)));
        if (!is_array($random_keys)) $random_keys = [$random_keys];
        foreach ($random_keys as $k) {
            $matched_tags[] = $tag_pool[$k];
        }
    }

    // Add one extra random tag ~40% of the time for variety
    if (rand(1, 100) <= 40) {
        $extra = $tag_pool[array_rand($tag_pool)];
        $matched_tags[] = $extra;
    }

    // Deduplicate and limit to 4 tags max
    $matched_tags = array_unique($matched_tags);
    $matched_tags = array_slice($matched_tags, 0, 4);
    $tags_csv = implode(', ', $matched_tags);

    $stmt->execute([$tags_csv, $ticket['id']]);
    $updated++;
    echo "  #{$ticket['id']}: {$tags_csv}\n";
}

echo "\n✓ Tagged {$updated} tickets.\n";

// 5. Summary — distinct tags
$all_tags = $pdo->query("SELECT DISTINCT tags FROM tickets WHERE tags IS NOT NULL AND tags != ''")->fetchAll();
$unique = [];
foreach ($all_tags as $row) {
    foreach (explode(',', $row['tags']) as $t) {
        $t = trim($t);
        if ($t !== '') $unique[$t] = true;
    }
}
ksort($unique);
echo "Distinct tags in use (" . count($unique) . "): " . implode(', ', array_keys($unique)) . "\n";
