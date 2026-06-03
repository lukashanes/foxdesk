<?php

$root = dirname(__DIR__);

function assert_home_redirect_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$files = [
    'index.php',
    'includes/security-helpers.php',
    'pages/login.php',
    'pages/forgot-password.php',
    'pages/reset-password.php',
    'pages/user-profile.php',
    'pages/new-ticket.php',
    'pages/admin/agent-connect.php',
    'pages/admin/reports.php',
    'pages/admin/migration-export.php',
];

foreach ($files as $file) {
    $contents = file_get_contents($root . '/' . $file);
    assert_home_redirect_contract($contents !== false, $file . ' must be readable.');
    assert_home_redirect_contract(
        strpos($contents, "header('Location: index.php?page=dashboard');") === false,
        $file . ' must not hard-code dashboard as a fallback redirect.'
    );
}

foreach ($files as $file) {
    $contents = file_get_contents($root . '/' . $file);
    assert_home_redirect_contract(
        strpos($contents, 'foxdesk_authenticated_home_page') !== false,
        $file . ' should use authenticated home routing for fallback redirects.'
    );
}

echo "Home redirect contract OK\n";
