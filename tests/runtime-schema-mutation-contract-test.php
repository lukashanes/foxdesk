<?php
$root = dirname(__DIR__);
$roots = [$root . '/includes', $root . '/pages'];
$allow = [
    realpath($root . '/includes/update-functions.php'),
    realpath($root . '/includes/schema-migration-runner.php'),
    // Upgrade steps shared by install.php and upgrade.php; never loaded per request.
    realpath($root . '/includes/upgrade-functions.php'),
];
$pattern = '/\b(?:CREATE\s+TABLE|ALTER\s+TABLE|CREATE\s+(?:UNIQUE\s+)?INDEX|DROP\s+(?:TABLE|INDEX))\b/i';
$violations = [];

foreach ($roots as $path) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $real = $file->getRealPath();
        if ($real && in_array($real, $allow, true)) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (preg_match($pattern, $source)) {
            $violations[] = str_replace($root . '/', '', $file->getPathname());
        }
    }
}

if ($violations) {
    fwrite(STDERR, "Request-time schema mutations found:\n- " . implode("\n- ", $violations) . "\n");
    exit(1);
}

$migrations = glob($root . '/migrations/*.{php,sql}', GLOB_BRACE) ?: [];
if (!$migrations) {
    fwrite(STDERR, "No versioned database migrations found.\n");
    exit(1);
}

echo "Runtime schema mutation contract passed.\n";
