<?php

$root = dirname(__DIR__);

$readme = file_get_contents($root . '/README.md');
$migration = file_get_contents($root . '/SELF_HOSTED_TO_SAAS_MIGRATION.md');

if ($readme === false || $migration === false) {
    fwrite(STDERR, "Unable to read self-hosted boundary docs.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(str_contains($readme, 'public self-hosted PHP FoxDesk release channel'), 'README must identify self-hosted as the public PHP release channel.');
$assert(str_contains($readme, 'FoxDesk SaaS repository'), 'README must point SaaS platform work to the SaaS repository.');
$assert(str_contains($readme, 'one-way self-hosted to SaaS migration bridge'), 'README must keep migration bridge in scope.');
$assert(str_contains($migration, 'preferred') && str_contains($migration, 'API migration bridge'), 'Migration docs must keep API bridge as preferred path.');
$assert(str_contains($migration, 'only the SaaS instance remains active'), 'Migration docs must keep single-active-instance rule.');

echo "Self-hosted technical debt boundary contract OK\n";
