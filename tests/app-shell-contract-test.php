<?php

$root = dirname(__DIR__);

$module = file_get_contents($root . '/includes/modules/app/app-shell.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$router = file_get_contents($root . '/includes/api/router.php');
$handler = file_get_contents($root . '/includes/api/app-handler.php');
$docs = file_get_contents($root . '/docs/product-architecture-refactor.md');

if ($module === false || $bootstrap === false || $router === false || $handler === false || $docs === false) {
    fwrite(STDERR, "Unable to read app shell files.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(str_contains($bootstrap, '/app/app-shell.php'), 'Module bootstrap must load app shell.');
$assert(str_contains($router, "require_once __DIR__ . '/app-handler.php'"), 'API router must load app handler.');
$assert(str_contains($router, "'app-shell' => 'api_app_shell'"), 'app-shell route is not registered.');
$assert(str_contains($handler, 'function api_app_shell()'), 'api_app_shell handler is missing.');
$assert(str_contains($handler, 'app_shell_payload($user)'), 'api_app_shell must delegate to app_shell_payload().');
$assert(str_contains($module, 'function app_shell_payload'), 'App shell payload helper is missing.');
$assert(str_contains($module, 'function app_shell_navigation'), 'App shell navigation helper is missing.');
$assert(str_contains($module, 'function app_shell_capabilities'), 'App shell capabilities helper is missing.');
$assert(str_contains($module, 'function app_shell_work_queues'), 'App shell work queue helper is missing.');
$assert(str_contains($module, 'function app_shell_inbox_queues'), 'App shell inbox queue helper is missing.');
$assert(str_contains($module, 'function app_shell_search_sections'), 'App shell search sections helper is missing.');
$assert(str_contains($module, 'function app_shell_reporting'), 'App shell reporting helper is missing.');
$assert(str_contains($module, "'schema_version' => 1"), 'App shell payload must expose a schema version.');
$assert(str_contains($module, "'key' => 'work'"), 'Dashboard navigation route key is missing.');
$assert(str_contains($module, "'label' => 'Dashboard'"), 'Dashboard navigation label is missing.');
$assert(!str_contains($module, "'label' => 'Work'"), 'App shell navigation must not expose the old Work label.');
$assert(!str_contains($module, "'key' => 'inbox'"), 'Inbox must not be a primary app-shell navigation item.');
$assert(str_contains($module, "'key' => 'reports'"), 'Reports navigation item is missing.');
$assert(str_contains($docs, 'sixth behavior change adds an authenticated `app-shell` API contract'), 'Architecture docs must describe milestone 6.');

echo "App shell contract OK\n";
