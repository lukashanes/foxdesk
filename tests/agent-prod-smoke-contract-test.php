<?php
$root = dirname(__DIR__);

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!file_exists($full)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
    return file_get_contents($full);
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$smoke = $read('examples/agent-api/agent-prod-smoke.js');
$readme = $read('examples/agent-api/README.md');
$env = $read('examples/agent-api/.env.example');
$package = $read('package.json');

$assert(str_contains($smoke, "require('./mcp-server')"), 'Production smoke must exercise the MCP wrapper.');
$assert(str_contains($smoke, 'FOXDESK_BASE_URL'), 'Production smoke must require a base URL.');
$assert(str_contains($smoke, 'FOXDESK_API_TOKEN'), 'Production smoke must require an API token.');
$assert(str_contains($smoke, 'FOXDESK_AGENT_PROD_WRITE'), 'Production smoke must gate real writes.');
$assert(str_contains($smoke, 'FOXDESK_AGENT_PROD_REPORT'), 'Production smoke must gate optional reporting checks.');
$assert(str_contains($smoke, 'foxdesk_list_tickets'), 'Production smoke must verify the live ticket list API.');
$assert(str_contains($smoke, 'foxdesk_get_ticket'), 'Production smoke must verify live ticket detail when data exists.');
$assert(str_contains($smoke, 'confirm: true'), 'Write smoke must explicitly confirm write tools.');
$assert(str_contains($smoke, 'redact(error.stack || error.message)'), 'Production smoke must redact secrets from errors.');
$assert(!preg_match('/fdx_(live|test)_[A-Za-z0-9]/', $smoke . $readme . $env), 'Production smoke docs must not contain real-looking API tokens.');

$assert(str_contains($package, 'agent:prod-smoke'), 'package.json must expose agent:prod-smoke.');
$assert(str_contains($package, 'test:agent-prod-smoke-contract'), 'package.json must expose test:agent-prod-smoke-contract.');
$assert(str_contains($readme, 'agent:prod-smoke'), 'Agent README must document the production smoke command.');
$assert(str_contains($readme, 'FOXDESK_AGENT_PROD_WRITE=1'), 'Agent README must document the explicit write-smoke switch.');
$assert(str_contains($env, 'FOXDESK_AGENT_PROD_WRITE=0'), '.env.example must default production writes off.');

echo "Agent production smoke contract OK\n";
