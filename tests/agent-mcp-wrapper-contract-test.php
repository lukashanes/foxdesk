<?php

$root = dirname(__DIR__);

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
    'examples/agent-api/mcp-server.js',
    'docs/AGENT_MCP_SERVER.md',
    'docs/AGENT_API_CONTROL.md',
    'docs/AGENT_API_QUICKSTART.md',
    'docs/AGENT_API_MILESTONES.md',
    'examples/agent-api/agent-tools.json',
    'examples/agent-api/mcp-smoke.js',
    'examples/agent-api/README.md',
    'package.json',
] as $relative) {
    $assert(is_file($root . '/' . $relative), 'Missing MCP wrapper file: ' . $relative);
}

$server = $read('examples/agent-api/mcp-server.js');
$docs = $read('docs/AGENT_MCP_SERVER.md');
$control = $read('docs/AGENT_API_CONTROL.md');
$quickstart = $read('docs/AGENT_API_QUICKSTART.md');
$milestones = $read('docs/AGENT_API_MILESTONES.md');
$manifest = $read('examples/agent-api/agent-tools.json');
$smoke = $read('examples/agent-api/mcp-smoke.js');
$readme = $read('examples/agent-api/README.md');
$package = $read('package.json');

foreach ([
    'foxdesk_agent_manifest',
    'TOOL_POLICY',
    'TOOL_MANIFEST',
    'tools/list',
    'tools/call',
    'process.stdout.write(JSON.stringify(message)',
    'FOXDESK_AGENT_ENV',
    'FOXDESK_API_TOKEN',
    'Authorization',
    'Idempotency-Key',
    'Never print',
    'foxdesk_list_tickets',
    'foxdesk_get_ticket',
    'foxdesk_create_ticket',
    'foxdesk_add_comment',
    'foxdesk_log_time',
    'foxdesk_prepare_report',
    'app-ticket-list',
    'app-ticket-detail',
    'app-create-ticket',
    'app-add-comment',
    'app-log-time',
    'app-reporting-review',
    'agentDryRunRequested',
    'requireWriteConfirmation',
    'writeDryRunPlan',
    'confirm:true',
    'dry_run',
] as $needle) {
    $assert(str_contains($server, $needle), 'MCP server missing: ' . $needle);
}

foreach ([
    'node examples/agent-api/mcp-server.js',
    'npm run agent:mcp',
    'npm run test:agent-mcp-smoke',
    'FOXDESK_AGENT_ENV',
    'FOXDESK_AGENT_DRY_RUN',
    'FOXDESK_AGENT_CONFIRM_WRITES',
    'mcpServers',
    'foxdesk_agent_manifest',
    'foxdesk_create_ticket',
    'foxdesk_log_time',
    'foxdesk_prepare_report',
    'tickets:write',
    'time:write',
    'reports:read',
    '401',
    '403',
] as $needle) {
    $assert(str_contains($docs, $needle), 'MCP docs missing: ' . $needle);
}

$assert(str_contains($control, 'mcp-server.js'), 'Agent control docs must mark the MCP wrapper as implemented.');
$assert(str_contains($control, 'AGENT_API_MILESTONES.md'), 'Agent control docs must link milestone evidence.');
$assert(!str_contains($control, 'A dedicated MCP server wrapper around these HTTP endpoints.'), 'MCP wrapper must not remain listed as future-facing.');
$assert(!str_contains($control, 'Deeper dry-run support for destructive actions.'), 'Dry-run support must not remain listed as future-facing.');
$assert(str_contains($quickstart, 'AGENT_MCP_SERVER.md'), 'Quickstart must link the MCP server docs.');
$assert(str_contains($milestones, 'Milník 4'), 'Milestone evidence must include Milník 4.');
$assert(str_contains($milestones, 'Milník 5'), 'Milestone evidence must include Milník 5.');
$assert(str_contains($milestones, 'Milník 6'), 'Milestone evidence must include Milník 6.');
$assert(str_contains($manifest, 'foxdesk_agent_manifest'), 'Tool manifest must include the manifest tool.');
$assert(str_contains($manifest, '"writes_require_confirmation": true'), 'Tool manifest must require write confirmation.');
$assert(str_contains($manifest, '"supports_dry_run": true'), 'Tool manifest must mark write dry-run support.');
$assert(str_contains($smoke, 'dry_run: true'), 'MCP smoke must test dry-run writes.');
$assert(str_contains($smoke, 'confirm:true'), 'MCP smoke must test blocked writes without confirmation.');
$assert(str_contains($smoke, 'fdx_test_secret_should_be_redacted'), 'MCP smoke must test token redaction.');
$assert(str_contains($readme, 'npm run agent:mcp'), 'Examples README must mention the MCP server.');
$assert(str_contains($readme, 'confirm:true'), 'Examples README must mention write confirmation.');
$assert(str_contains($package, 'agent:mcp'), 'package.json must expose agent:mcp.');
$assert(str_contains($package, 'test:agent-mcp'), 'package.json must expose test:agent-mcp.');
$assert(str_contains($package, 'test:agent-mcp-smoke'), 'package.json must expose test:agent-mcp-smoke.');
$assert(!preg_match('/sk_(live|test)_[A-Za-z0-9]/', $server . $docs), 'MCP wrapper must not contain Stripe-like secrets.');
$assert(!preg_match('/fdx_(live|test)_[A-Za-z0-9]/', $server . $docs . $manifest), 'MCP wrapper must not contain real-looking FoxDesk tokens.');

echo "Agent MCP wrapper contract OK\n";
