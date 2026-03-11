#!/usr/bin/env php
<?php
/**
 * FoxDesk Update Package Builder
 *
 * Creates a properly structured update ZIP that can be:
 *   1) Uploaded manually via Settings > System > Upload
 *   2) Hosted on GitHub Releases for auto-update
 *
 * Usage:
 *   php bin/build-update.php                     # build ZIP for current version
 *   php bin/build-update.php 0.3.47              # bump to 0.3.47, then build
 *   php bin/build-update.php 0.3.47 --dry-run    # show what would be included
 *   php bin/build-update.php --current            # rebuild current version ZIP
 *
 * Output: build/foxdesk-{version}.zip
 *
 * Package structure expected by apply_update():
 *   foxdesk-{version}/
 *     version.json
 *     files/          ← all application files
 *     migrations/     ← SQL migration scripts (optional)
 */

// ── Configuration ───────────────────────────────────────────────────────────

$base_path = dirname(__DIR__);

// Files and directories to EXCLUDE from the update package
$exclude_patterns = [
    // Server-specific / secrets
    'config.php',
    '.env',
    '.env.*',

    // Runtime data
    'backups/',
    'uploads/',
    'storage/',

    // Dev infrastructure
    'bin/',
    'docker-compose.yml',
    'docker-entrypoint.sh',
    'Dockerfile',
    '.dockerignore',

    // Dev docs
    'CLAUDE.md',
    'AGENT-GUIDE.md',
    'APP-FEATURES.md',
    'UPDATE-GUIDE.md',
    'HANDOFF.md',
    'MANUAL.md',

    // Build artifacts
    'build/',
    'updates/',

    // IDE / tools
    '.idea/',
    '.vscode/',
    '.claude/',
    '.playwright-mcp/',
    '*.swp',
    '*.swo',

    // OS files
    '.DS_Store',
    'Thumbs.db',

    // Git
    '.git/',
    '.gitignore',

    // Dependencies (not used by FoxDesk)
    'node_modules/',
    'vendor/',

    // Dev utility scripts
    'create_test_user.php',
    'fix_encoding.*',

    // Screenshots / audit images
    'audit-*.png',
    'v0*.png',

    // Test data files
    'seed_data.sql',
    'seed_emails.sql',

    // Build ZIPs
    '*.zip',
];

// ── Parse Arguments ─────────────────────────────────────────────────────────

$new_version = null;
$dry_run = false;
$rebuild_current = false;
$changelog_items = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dry_run = true;
    } elseif ($arg === '--current') {
        $rebuild_current = true;
    } elseif (str_starts_with($arg, '--changelog=')) {
        $changelog_items[] = substr($arg, strlen('--changelog='));
    } elseif (!str_starts_with($arg, '-') && $new_version === null) {
        $new_version = $arg;
    }
}

// ── Read Current Version ────────────────────────────────────────────────────

$index_file = $base_path . '/index.php';
$version_file = $base_path . '/version.json';

if (!file_exists($index_file)) {
    fwrite(STDERR, "ERROR: index.php not found at $index_file\n");
    exit(1);
}

$index_content = file_get_contents($index_file);
if (!preg_match("/define\('APP_VERSION',\s*'([^']+)'\)/", $index_content, $m)) {
    fwrite(STDERR, "ERROR: APP_VERSION not found in index.php\n");
    exit(1);
}
$current_version = $m[1];

// Determine target version
if ($rebuild_current || $new_version === null) {
    $target_version = $current_version;
} else {
    $target_version = $new_version;
}

// Validate version format
if (!preg_match('/^\d+\.\d+\.\d+$/', $target_version)) {
    fwrite(STDERR, "ERROR: Invalid version format '$target_version'. Expected X.Y.Z\n");
    exit(1);
}

echo "╔══════════════════════════════════════════╗\n";
echo "║  FoxDesk Update Package Builder          ║\n";
echo "╚══════════════════════════════════════════╝\n\n";

if ($dry_run) {
    echo "  MODE: Dry run (no files will be created)\n";
}
echo "  Current version: $current_version\n";
echo "  Target version:  $target_version\n";

if ($target_version !== $current_version) {
    if (version_compare($target_version, $current_version, '<=')) {
        fwrite(STDERR, "\n  WARNING: Target version is not newer than current.\n");
    }
}
echo "\n";

// ── Bump Version (if needed) ────────────────────────────────────────────────

if ($target_version !== $current_version && !$dry_run) {
    echo "Bumping version $current_version → $target_version...\n";

    // Update index.php
    $new_index = preg_replace(
        "/define\('APP_VERSION',\s*'[^']+'\)/",
        "define('APP_VERSION', '$target_version')",
        $index_content
    );
    file_put_contents($index_file, $new_index);
    echo "  ✓ index.php updated\n";

    // Update version.json
    $vj = json_decode(file_get_contents($version_file), true) ?: [];
    $vj['version'] = $target_version;
    $vj['date'] = date('Y-m-d');
    if (!empty($changelog_items)) {
        $vj['changelog'] = $changelog_items;
    }
    file_put_contents($version_file, json_encode($vj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "  ✓ version.json updated\n\n";
} elseif ($dry_run && $target_version !== $current_version) {
    echo "[DRY RUN] Would bump version $current_version → $target_version\n\n";
}

// Reload version.json for package metadata
$version_json = json_decode(file_get_contents($version_file), true) ?: [];
$version_json['version'] = $target_version;
if (!isset($version_json['min_php'])) {
    $version_json['min_php'] = '8.1';
}
if (!isset($version_json['changelog'])) {
    $version_json['changelog'] = [];
}
if (!isset($version_json['delete_files'])) {
    $version_json['delete_files'] = [];
}

// ── Collect Files ───────────────────────────────────────────────────────────

echo "Scanning files...\n";

/**
 * Check if a relative path matches any of the exclude patterns.
 */
function should_exclude(string $relative_path, array $patterns): bool
{
    $normalized = str_replace('\\', '/', $relative_path);

    foreach ($patterns as $pattern) {
        $pattern = str_replace('\\', '/', $pattern);

        // Directory pattern (ends with /)
        if (str_ends_with($pattern, '/')) {
            $dir = rtrim($pattern, '/');
            if ($normalized === $dir || str_starts_with($normalized, $dir . '/')) {
                return true;
            }
            // Also check subdirectories
            if (str_contains($normalized, '/' . $dir . '/') || str_ends_with($normalized, '/' . $dir)) {
                return true;
            }
            continue;
        }

        // Wildcard pattern
        if (str_contains($pattern, '*')) {
            if (fnmatch($pattern, $normalized) || fnmatch($pattern, basename($normalized))) {
                return true;
            }
            continue;
        }

        // Exact match (filename or path)
        if ($normalized === $pattern || basename($normalized) === $pattern) {
            return true;
        }
    }

    return false;
}

$files = [];
$total_size = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base_path, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $full_path = $item->getPathname();
    $relative = substr(str_replace('\\', '/', $full_path), strlen(str_replace('\\', '/', $base_path)) + 1);

    if (should_exclude($relative, $exclude_patterns)) {
        continue;
    }

    if ($item->isFile()) {
        $files[] = [
            'full' => $full_path,
            'relative' => $relative,
            'size' => $item->getSize(),
        ];
        $total_size += $item->getSize();
    }
}

echo "  Found " . count($files) . " files (" . format_size($total_size) . ")\n\n";

if ($dry_run) {
    echo "Files that would be included:\n";
    echo str_repeat('─', 60) . "\n";
    foreach ($files as $f) {
        printf("  %-50s %s\n", $f['relative'], format_size($f['size']));
    }
    echo str_repeat('─', 60) . "\n";
    echo "  Total: " . count($files) . " files (" . format_size($total_size) . ")\n\n";

    // Show version.json that would be included
    echo "version.json contents:\n";
    echo json_encode($version_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    echo "[DRY RUN] No ZIP created.\n";
    exit(0);
}

// ── Check for SQL Migrations ────────────────────────────────────────────────

$migrations_dir = $base_path . '/migrations';
$migration_files = [];

if (is_dir($migrations_dir)) {
    $migration_files = glob($migrations_dir . '/*.sql') ?: [];
    sort($migration_files);
    if (!empty($migration_files)) {
        echo "Migrations found: " . count($migration_files) . "\n";
        foreach ($migration_files as $mf) {
            echo "  • " . basename($mf) . "\n";
        }
        echo "\n";
    }
}

// ── Build ZIP ───────────────────────────────────────────────────────────────

$build_dir = $base_path . '/build';
if (!is_dir($build_dir)) {
    mkdir($build_dir, 0755, true);
}

$zip_filename = "foxdesk-$target_version.zip";
$zip_path = $build_dir . '/' . $zip_filename;

// Remove old build if exists
if (file_exists($zip_path)) {
    unlink($zip_path);
}

echo "Building $zip_filename...\n";

$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "ERROR: Cannot create ZIP at $zip_path\n");
    exit(1);
}

$package_prefix = "foxdesk-$target_version";

// Add version.json at package root
$zip->addFromString(
    "$package_prefix/version.json",
    json_encode($version_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
);

// Add application files under files/
$added = 0;
foreach ($files as $f) {
    $zip->addFile($f['full'], "$package_prefix/files/" . $f['relative']);
    $added++;
}

// Add migrations under migrations/
foreach ($migration_files as $mf) {
    $zip->addFile($mf, "$package_prefix/migrations/" . basename($mf));
}

$zip->close();

$zip_size = filesize($zip_path);

echo "  ✓ $added files added\n";
if (!empty($migration_files)) {
    echo "  ✓ " . count($migration_files) . " migration(s) added\n";
}
echo "  ✓ Package: build/$zip_filename (" . format_size($zip_size) . ")\n\n";

// ── Generate SHA-256 Checksum ───────────────────────────────────────────────

$sha256 = hash_file('sha256', $zip_path);
$checksum_file = $build_dir . '/' . $zip_filename . '.sha256';
file_put_contents($checksum_file, "$sha256  $zip_filename\n");
echo "  SHA-256: $sha256\n";
echo "  Saved:   build/$zip_filename.sha256\n\n";

// ── Generate GitHub Release Notes ───────────────────────────────────────────

$release_notes = "## FoxDesk v$target_version\n\n";
$release_notes .= "Released: " . date('Y-m-d') . "\n\n";

if (!empty($version_json['changelog'])) {
    $release_notes .= "### Changes\n\n";
    foreach ($version_json['changelog'] as $item) {
        $release_notes .= "- $item\n";
    }
    $release_notes .= "\n";
}

$release_notes .= "### Installation\n\n";
$release_notes .= "**New install:** Download `$zip_filename`, extract, and follow setup wizard.\n\n";
$release_notes .= "**Update:** Go to Settings → System → Auto-Update and click \"Download & Install\",\n";
$release_notes .= "or upload `$zip_filename` manually.\n\n";
$release_notes .= "**SHA-256:** `$sha256`\n";

$notes_file = $build_dir . '/RELEASE-NOTES.md';
file_put_contents($notes_file, $release_notes);
echo "  Release notes: build/RELEASE-NOTES.md\n\n";

// ── Summary ─────────────────────────────────────────────────────────────────

echo "╔══════════════════════════════════════════╗\n";
echo "║  BUILD COMPLETE                          ║\n";
echo "╚══════════════════════════════════════════╝\n\n";
echo "  Version:  $target_version\n";
echo "  Package:  build/$zip_filename\n";
echo "  Size:     " . format_size($zip_size) . "\n";
echo "  Files:    $added\n";
echo "  SHA-256:  " . substr($sha256, 0, 16) . "...\n\n";

echo "Next steps:\n";
echo "  1. git add -A && git commit -m \"Release v$target_version\"\n";
echo "  2. git tag v$target_version\n";
echo "  3. git push origin main --tags\n";
echo "  4. Create GitHub Release:\n";
echo "     gh release create v$target_version build/$zip_filename \\\n";
echo "       --title \"FoxDesk v$target_version\" \\\n";
echo "       --notes-file build/RELEASE-NOTES.md\n\n";

// ── Helper Functions ────────────────────────────────────────────────────────

function format_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}
