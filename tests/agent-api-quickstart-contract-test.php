<?php

$root = dirname(__DIR__);
$expectedBaseUrl = 'https://helpdesk.example.com';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "Unable to read {$relative}\n");
        exit(1);
    }

    return $content;
};

foreach ([
    'docs/AGENT_API_QUICKSTART.md',
    'docs/AGENT_API_CONTROL.md',
    'examples/agent-api/.env.example',
    'examples/agent-api/_common.sh',
    'examples/agent-api/create-ticket.sh',
    'examples/agent-api/add-comment.sh',
    'examples/agent-api/log-time.sh',
    'examples/agent-api/prepare-report.sh',
    'examples/agent-api/codex-instructions.md',
    'examples/agent-api/claude-instructions.md',
    'examples/agent-api/README.md',
    'package.json',
    '.gitignore',
] as $relative) {
    $assert(is_file($root . '/' . $relative), 'Missing Agent API quickstart file: ' . $relative);
}

$gitignore = $read('.gitignore');
$quickstart = $read('docs/AGENT_API_QUICKSTART.md');
$control = $read('docs/AGENT_API_CONTROL.md');
$env = $read('examples/agent-api/.env.example');
$common = $read('examples/agent-api/_common.sh');
$readme = $read('examples/agent-api/README.md');
$codex = $read('examples/agent-api/codex-instructions.md');
$claude = $read('examples/agent-api/claude-instructions.md');
$package = $read('package.json');

$assert(str_contains($gitignore, 'examples/agent-api/.env'), 'Real Agent API .env must be ignored.');

foreach ([
    'Profile -> API access',
    'API access, not browser login',
    'do not open the web',
    '/index.php?page=login',
    'Authorization: Bearer',
    'cp examples/agent-api/.env.example examples/agent-api/.env',
    'create-ticket.sh',
    'log-time.sh',
    'prepare-report.sh',
    'Codex',
    'Claude',
    'tickets:write',
    'time:write',
    'reports:read',
    '401',
    '403',
] as $needle) {
    $assert(str_contains($quickstart, $needle), 'Quickstart missing: ' . $needle);
}

$assert(str_contains($control, 'AGENT_API_QUICKSTART.md'), 'Agent API control docs must link the quickstart.');
$assert(!str_contains($control, 'Customer-facing examples for Claude/Codex configs.'), 'Agent examples must not remain listed as future-facing.');

$assert(str_contains($env, 'FOXDESK_BASE_URL=' . $expectedBaseUrl), 'Env example must use the expected base URL.');
$assert(str_contains($env, 'FOXDESK_API_TOKEN=fdx_replace_with_token_from_profile'), 'Env example must use a placeholder token.');
foreach (['sk_live_', 'sk_test_', 'fdx_live_', 'fdx_test_', 'Bearer fdx_'] as $secretNeedle) {
    $assert(!str_contains($env, $secretNeedle), 'Env example must not contain a real-looking secret: ' . $secretNeedle);
}

foreach ([
    'Authorization: Bearer',
    'Idempotency-Key',
    'foxdesk_post_json',
    'foxdesk_get_json',
] as $needle) {
    $assert(str_contains($common, $needle), 'Shared shell helper missing: ' . $needle);
}

foreach ([
    'examples/agent-api/create-ticket.sh' => 'app-create-ticket',
    'examples/agent-api/add-comment.sh' => 'app-add-comment',
    'examples/agent-api/log-time.sh' => 'app-log-time',
    'examples/agent-api/prepare-report.sh' => 'app-reporting-review',
] as $script => $action) {
    $assert(str_contains($read($script), $action), $script . ' must call ' . $action);
}

foreach ([
    'curl -fsS -X POST',
    'app-create-ticket',
    'Authorization: Bearer $FOXDESK_API_TOKEN',
    'Idempotency-Key',
] as $needle) {
    $assert(str_contains($readme, $needle), 'README curl example missing: ' . $needle);
}

foreach ([$codex, $claude] as $agentDoc) {
    foreach ([
        'Never print FOXDESK_API_TOKEN',
        '/index.php?page=login',
        'Authorization: Bearer',
        '401',
        '403',
        'create-ticket.sh',
        'log-time.sh',
        'prepare-report.sh',
    ] as $needle) {
        $assert(str_contains($agentDoc, $needle), 'Agent instructions missing: ' . $needle);
    }
}

$assert(str_contains($package, 'test:agent-api-quickstart'), 'package.json must expose test:agent-api-quickstart.');

echo "Agent API quickstart contract OK\n";
