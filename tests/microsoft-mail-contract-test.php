<?php

$root = dirname(__DIR__);
if (!defined('SECRET_KEY')) {
    define('SECRET_KEY', 'microsoft-mail-contract-test-key-that-is-long-and-stable');
}

require_once $root . '/includes/microsoft-mail-functions.php';

$assert = static function ($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$secret = 'test-secret-' . bin2hex(random_bytes(8));
$ciphertext = microsoft_mail_encrypt($secret);
$assert(is_string($ciphertext) && $ciphertext !== $secret, 'Microsoft credentials must be encrypted.');
$assert(!str_contains($ciphertext, $secret), 'Ciphertext must not expose the plaintext credential.');
$assert(microsoft_mail_decrypt($ciphertext) === $secret, 'Microsoft credential encryption must round-trip.');

$scopes = microsoft_mail_scopes();
foreach (['offline_access', 'https://graph.microsoft.com/User.Read', 'https://graph.microsoft.com/Mail.ReadWrite', 'https://graph.microsoft.com/Mail.Send'] as $scope) {
    $assert(in_array($scope, $scopes, true), "Missing Microsoft scope: {$scope}");
}
$assert(str_starts_with(microsoft_mail_authority('organizations'), 'https://login.microsoftonline.com/organizations/'), 'Microsoft authority must stay on the expected host.');
try {
    microsoft_mail_authority('attacker.example/path');
    $assert(false, 'Unsafe Microsoft tenant identifiers must be rejected.');
} catch (InvalidArgumentException $e) {
    // Expected.
}

$module = file_get_contents($root . '/includes/microsoft-mail-functions.php');
$ingest = file_get_contents($root . '/includes/microsoft-email-ingest.php');
$mailer = file_get_contents($root . '/includes/mailer.php');
$index = file_get_contents($root . '/index.php');
$schema = file_get_contents($root . '/includes/schema.sql');
$settings = file_get_contents($root . '/includes/modules/settings/settings-actions.php');
$view = file_get_contents($root . '/includes/modules/settings/views/microsoft-mail.php');
$docs = file_get_contents($root . '/docs/MICROSOFT_365_MAIL.md');

$assert(str_contains($module, "'code_challenge_method' => 'S256'"), 'Microsoft OAuth must use PKCE S256.');
$assert(str_contains($module, "hash_equals"), 'Microsoft OAuth state must use constant-time comparison.');
$assert(str_contains($module, "CURLOPT_FOLLOWLOCATION => false"), 'Microsoft requests must not follow untrusted redirects.');
$assert(str_contains($module, "microsoft_mail_graph_request('me/sendMail'"), 'Microsoft Graph sendMail endpoint is missing.');
$assert(str_contains($module, "refresh_token_ciphertext"), 'Refresh tokens must use encrypted storage.');
$assert(!preg_match('/\b(access_token|refresh_token|client_secret)\s+(?:TEXT|VARCHAR|MEDIUMTEXT)/i', $schema), 'Schema must not contain plaintext Microsoft credential columns.');
$assert(str_contains($schema, 'access_token_ciphertext MEDIUMTEXT'), 'Encrypted access-token column is missing.');
$assert(str_contains($index, "case 'microsoft-oauth':"), 'Authenticated Microsoft OAuth callback route is missing.');
$assert(str_contains($index, 'security_request_log_context()') && !str_contains($index, '$log_get'), 'OAuth secrets must not be copied into request logs.');
$assert(str_contains($settings, "microsoft_mail_begin_authorization"), 'Microsoft settings action is not wired.');
$assert(str_contains($view, 'data-testid="microsoft-mail-settings"'), 'Microsoft settings UI is missing.');
$assert(str_contains($view, 'confirm(this.dataset.confirm)'), 'Translated disconnect confirmation must not be embedded in a JavaScript string literal.');
$assert(str_contains($mailer, "microsoft_mail_is_active('outbound')"), 'Microsoft Graph outbound routing is missing.');
$assert(str_contains($ingest, 'email_ingest_allowed_sender'), 'Microsoft ingest must preserve sender authorization.');
$assert(strpos($ingest, 'if ($dryRun)') < strpos($ingest, 'email_ingest_resolve_requester_user_id'), 'Microsoft dry run must stop before requester creation.');
$assert(str_contains($ingest, 'if (!$dryRun) {') && str_contains($ingest, 'email_ingest_log'), 'Microsoft dry run must not write ingest logs.');
$assert(str_contains($ingest, 'email_ingest_store_attachment'), 'Microsoft ingest must preserve attachment validation and storage.');
$assert(str_contains($ingest, 'microsoft_mail_finish_message'), 'Microsoft ingest must finalize handled Graph messages.');
$assert(!str_contains($docs, 'Password: Your password or app password'), 'Documentation must not recommend password-based Microsoft auth.');

$registry = json_decode((string) file_get_contents($root . '/locales/registry.json'), true);
$locales = is_array($registry['locales'] ?? null)
    ? array_values(array_filter(array_map(static fn(array $locale): string => (string) ($locale['tag'] ?? ''), $registry['locales'])))
    : [];
foreach ($locales as $locale) {
    $catalog = json_decode((string) file_get_contents($root . '/locales/catalogs/' . $locale . '.json'), true);
    foreach ([
        'Connect Microsoft mailbox',
        'Microsoft connection failed: {error}',
        'Microsoft mailbox {email} connected.',
    ] as $key) {
        $value = (string) ($catalog[$key] ?? '');
        $assert($value !== '', "{$locale} is missing Microsoft translation: {$key}");
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $key, $sourcePlaceholders);
        foreach ($sourcePlaceholders[0] as $placeholder) {
            $assert(str_contains($value, $placeholder), "{$locale} lost placeholder {$placeholder} in {$key}");
        }
    }
}

echo "Microsoft mail contract passed.\n";
