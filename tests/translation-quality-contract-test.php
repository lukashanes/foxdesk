<?php

$root = dirname(__DIR__);

function assert_translation_quality(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function translation_placeholders(string $value): array
{
    preg_match_all('/\{[a-zA-Z0-9_]+\}/', $value, $matches);
    $placeholders = $matches[0] ?? [];
    sort($placeholders);
    return array_values(array_unique($placeholders));
}

$source = include $root . '/includes/lang/en.php';
$languages = ['cs', 'de', 'es', 'it'];
$sameAsEnglishAllowlist = array_fill_keys([
    'API',
    'API Base:',
    'Admin',
    'Agent',
    'AI',
    'AI agent',
    'April',
    'August',
    'Color',
    'Dashboard',
    'Details',
    'Domain',
    'Email',
    'Error',
    'Favicon',
    'Figma design',
    'FoxDesk',
    'General',
    'Google Docs',
    'Google Sheets',
    'Google Slides',
    'Helpdesk',
    'IMAP port',
    'IMAP server',
    'Inbox',
    'Logo',
    'Manual',
    'Model',
    'Name',
    'Navigation',
    'Normal',
    'November',
    'Optional',
    'Password',
    'Port',
    'Position',
    'Prefix',
    'Role',
    'SMTP server',
    'SSL',
    'SSL (port 465)',
    'September',
    'Status',
    'Status: {status}',
    'System',
    'TLS',
    'TLS (port 587)',
    'Tags',
    'Team',
    'Ticket',
    'Tickets',
    'Timer',
    'Token',
    'Token:',
    'Total',
    'Triage',
    'Variable',
    'Variables',
    'Version {version}',
    'Workspace',
    'YouTube video',
    '{count} tickets',
], true);

foreach ($languages as $language) {
    $translations = include $root . '/includes/lang/' . $language . '.php';
    $missing = array_diff_key($source, $translations);
    $extra = array_diff_key($translations, $source);

    assert_translation_quality($missing === [], strtoupper($language) . ' has missing translation keys: ' . implode(', ', array_keys($missing)));
    assert_translation_quality($extra === [], strtoupper($language) . ' has extra translation keys: ' . implode(', ', array_keys($extra)));

    foreach ($source as $key => $sourceValue) {
        $translatedValue = (string) ($translations[$key] ?? '');
        assert_translation_quality(
            translation_placeholders((string) $sourceValue) === translation_placeholders($translatedValue),
            strtoupper($language) . ' placeholder mismatch for key: ' . $key
        );

        if ($translatedValue === (string) $sourceValue) {
            assert_translation_quality(
                isset($sameAsEnglishAllowlist[$translatedValue]),
                strtoupper($language) . ' keeps non-allowlisted English copy: ' . $key
            );
        }
    }
}

echo "Translation quality contract OK\n";
