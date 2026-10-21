<?php
/**
 * Self-hosted FoxDesk must not load runtime code, styles or fonts from a CDN.
 * Every library lives under assets/vendor/<name>/<version>/ and the CSP only
 * allows 'self' for scripts, styles and fonts.
 */

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'No external CDN contract failed: ' . $message . PHP_EOL);
        exit(1);
    }
};

$cdn_pattern = '~(?:cdn\.jsdelivr\.net|unpkg\.com|cdnjs\.cloudflare\.com|cdn\.tailwindcss\.com|cdn\.quilljs\.com|fonts\.googleapis\.com|fonts\.gstatic\.com|ajax\.googleapis\.com|code\.jquery\.com|stackpath\.bootstrapcdn\.com|maxcdn\.bootstrapcdn\.com|cdn\.skypack\.dev|esm\.sh|use\.fontawesome\.com|kit\.fontawesome\.com)~i';

$files = [];
foreach (['includes', 'pages', 'assets/js', 'assets/css'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('/\.(php|js|mjs|css|html)$/i', $file->getFilename())) {
            $files[] = $file->getPathname();
        }
    }
}
foreach (glob($root . '/*.{php,js,css}', GLOB_BRACE) as $file) {
    $files[] = $file;
}

$offenders = [];
$vendor_refs = [];
foreach ($files as $path) {
    $contents = (string) file_get_contents($path);
    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    if (preg_match_all($cdn_pattern, $contents, $matches)) {
        $offenders[] = $relative . ' (' . implode(', ', array_unique($matches[0])) . ')';
    }
    if (preg_match_all('~assets/vendor/[A-Za-z0-9._/-]+\.(?:js|mjs|css)~', $contents, $refs)) {
        foreach ($refs[0] as $ref) {
            $vendor_refs[$ref] = $relative;
        }
    }
}

$assert($offenders === [], "templates and scripts reference a CDN host:\n  " . implode("\n  ", $offenders));
$assert(count($vendor_refs) >= 9, 'expected the vendored runtime libraries to be referenced.');
foreach ($vendor_refs as $ref => $source) {
    $assert(is_file($root . '/' . $ref), "{$source} references missing vendored file {$ref}.");
}
foreach ([
    'assets/vendor/alpinejs/3.14.8/alpine.min.js',
    'assets/vendor/chartjs/4.4.0/chart.umd.js',
    'assets/vendor/flatpickr/4.6.13/flatpickr.min.js',
    'assets/vendor/flatpickr/4.6.13/flatpickr.min.css',
    'assets/vendor/flatpickr/4.6.13/themes/dark.css',
    'assets/vendor/qrcode/1.5.3/qrcode.min.js',
    'assets/vendor/quill/2.0.2/quill.js',
    'assets/vendor/quill/2.0.2/quill.snow.css',
    'assets/vendor/sortablejs/1.15.0/Sortable.min.js',
] as $required) {
    $assert(isset($vendor_refs[$required]), "{$required} must be used by a template.");
}

$security = (string) file_get_contents($root . '/includes/security-helpers.php');
$assert(preg_match('~Content-Security-Policy: default-src \'self\';(.*?)"\);~s', $security, $csp) === 1, 'CSP header not found.');
foreach (['script-src', 'style-src', 'font-src'] as $directive) {
    $assert(preg_match('~"' . $directive . ' ([^;]*);~', $csp[1], $value) === 1, "{$directive} missing from CSP.");
    $assert(!str_contains($value[1], 'http'), "{$directive} must not allow external hosts: {$value[1]}");
}

echo "No external CDN contract OK\n";
