<?php
/**
 * Microsoft 365 / Outlook OAuth and Microsoft Graph mail provider.
 *
 * Passwords and OAuth tokens are never stored in plaintext. Self-hosted
 * installations can provide the Entra application through environment
 * variables or the settings form; SaaS can provide one multi-tenant app.
 */

function microsoft_mail_env(string $name, string $default = ''): string
{
    if (defined($name)) {
        return trim((string) constant($name));
    }
    $value = getenv($name);
    return $value === false ? $default : trim((string) $value);
}

function microsoft_mail_tenant_id(): int
{
    if (function_exists('current_tenant_id')) {
        return max(0, (int) current_tenant_id());
    }
    return 0;
}

function microsoft_mail_connection(): ?array
{
    try {
        $row = db_fetch_one(
            "SELECT * FROM email_provider_connections WHERE tenant_id = ? AND provider = 'microsoft' LIMIT 1",
            [microsoft_mail_tenant_id()]
        );
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function microsoft_mail_encryption_key(): string
{
    $configured = microsoft_mail_env('MICROSOFT_MAIL_ENCRYPTION_KEY');
    if ($configured === '' && defined('SECRET_KEY')) {
        $configured = (string) SECRET_KEY;
    }
    if ($configured === '') {
        throw new RuntimeException('SECRET_KEY or MICROSOFT_MAIL_ENCRYPTION_KEY is required.');
    }
    return hash('sha256', $configured, true);
}

function microsoft_mail_encrypt(?string $plaintext): ?string
{
    if ($plaintext === null || $plaintext === '') {
        return null;
    }
    $key = microsoft_mail_encryption_key();

    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return 's1:' . base64_encode($nonce . $ciphertext);
    }

    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($ciphertext)) {
            throw new RuntimeException('Unable to encrypt Microsoft credentials.');
        }
        return 'o1:' . base64_encode($iv . $tag . $ciphertext);
    }

    throw new RuntimeException('Sodium or OpenSSL is required to protect Microsoft credentials.');
}

function microsoft_mail_decrypt(?string $encoded): string
{
    $encoded = trim((string) $encoded);
    if ($encoded === '') {
        return '';
    }
    $key = microsoft_mail_encryption_key();

    if (str_starts_with($encoded, 's1:')) {
        $raw = base64_decode(substr($encoded, 3), true);
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Stored Microsoft credential is invalid.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        if (!is_string($plaintext)) {
            throw new RuntimeException('Stored Microsoft credential cannot be decrypted.');
        }
        return $plaintext;
    }

    if (str_starts_with($encoded, 'o1:')) {
        $raw = base64_decode(substr($encoded, 3), true);
        if (!is_string($raw) || strlen($raw) <= 28) {
            throw new RuntimeException('Stored Microsoft credential is invalid.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $plaintext = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($plaintext)) {
            throw new RuntimeException('Stored Microsoft credential cannot be decrypted.');
        }
        return $plaintext;
    }

    throw new RuntimeException('Stored Microsoft credential uses an unsupported format.');
}

function microsoft_mail_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function microsoft_mail_redirect_uri(): string
{
    $configured = microsoft_mail_env('MICROSOFT_MAIL_REDIRECT_URI');
    if ($configured !== '') {
        return $configured;
    }
    $base = function_exists('get_app_url') ? get_app_url() : (defined('APP_URL') ? (string) APP_URL : '');
    if ($base === '') {
        throw new RuntimeException('The application URL is required for Microsoft OAuth.');
    }
    return rtrim($base, '/') . '/index.php?page=microsoft-oauth';
}

function microsoft_mail_authority(string $tenantIdentifier): string
{
    $tenantIdentifier = trim($tenantIdentifier);
    if ($tenantIdentifier === '') {
        $tenantIdentifier = 'common';
    }
    if (!preg_match('/^[A-Za-z0-9.-]{1,191}$/', $tenantIdentifier)) {
        throw new InvalidArgumentException('Invalid Microsoft tenant identifier.');
    }
    return 'https://login.microsoftonline.com/' . rawurlencode($tenantIdentifier) . '/oauth2/v2.0';
}

function microsoft_mail_scopes(): array
{
    return [
        'openid',
        'profile',
        'email',
        'offline_access',
        'https://graph.microsoft.com/User.Read',
        'https://graph.microsoft.com/Mail.ReadWrite',
        'https://graph.microsoft.com/Mail.Send',
    ];
}

function microsoft_mail_config(?array $connection = null): array
{
    $connection = $connection ?? microsoft_mail_connection() ?? [];
    $clientId = microsoft_mail_env('MICROSOFT_MAIL_CLIENT_ID');
    if ($clientId === '') {
        $clientId = trim((string) ($connection['client_id'] ?? ''));
    }

    $tenantIdentifier = microsoft_mail_env('MICROSOFT_MAIL_TENANT_ID');
    if ($tenantIdentifier === '') {
        $tenantIdentifier = trim((string) ($connection['tenant_identifier'] ?? 'common')) ?: 'common';
    }

    $clientSecret = microsoft_mail_env('MICROSOFT_MAIL_CLIENT_SECRET');
    if ($clientSecret === '' && !empty($connection['client_secret_ciphertext'])) {
        $clientSecret = microsoft_mail_decrypt((string) $connection['client_secret_ciphertext']);
    }

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'tenant_identifier' => $tenantIdentifier,
        'redirect_uri' => microsoft_mail_redirect_uri(),
    ];
}

function microsoft_mail_http(
    string $url,
    string $method = 'GET',
    ?array $payload = null,
    array $headers = [],
    bool $form = false
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for Microsoft 365 integration.');
    }
    if (!str_starts_with($url, 'https://login.microsoftonline.com/')
        && !str_starts_with($url, 'https://graph.microsoft.com/')) {
        throw new InvalidArgumentException('Unexpected Microsoft API endpoint.');
    }

    $requestHeaders = array_values($headers);
    $body = null;
    if ($payload !== null) {
        if ($form) {
            $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
            $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($body)) {
                throw new RuntimeException('Unable to encode Microsoft API request.');
            }
            $requestHeaders[] = 'Content-Type: application/json';
        }
    }
    $requestHeaders[] = 'Accept: application/json';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Microsoft request failed: ' . $error);
    }

    $decoded = trim((string) $responseBody) === ''
        ? []
        : json_decode((string) $responseBody, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => substr((string) $responseBody, 0, 500)];
    }

    return ['status' => $status, 'body' => $decoded];
}

function microsoft_mail_assert_success(array $response, string $operation): array
{
    $status = (int) ($response['status'] ?? 0);
    if ($status >= 200 && $status < 300) {
        return (array) ($response['body'] ?? []);
    }

    $body = (array) ($response['body'] ?? []);
    $message = (string) ($body['error']['message'] ?? $body['error_description'] ?? ('HTTP ' . $status));
    throw new RuntimeException($operation . ' failed: ' . $message);
}

function microsoft_mail_save_pending(
    string $tenantIdentifier,
    string $clientId,
    string $clientSecret,
    string $stateHash,
    string $verifierCiphertext,
    ?int $createdBy
): void {
    $storedClientSecret = microsoft_mail_env('MICROSOFT_MAIL_CLIENT_SECRET') !== ''
        ? null
        : microsoft_mail_encrypt($clientSecret);
    db_query(
        "INSERT INTO email_provider_connections
            (tenant_id, provider, tenant_identifier, client_id, client_secret_ciphertext,
             status, oauth_state_hash, oauth_state_expires_at, code_verifier_ciphertext,
             created_by, created_at, updated_at)
         VALUES (?, 'microsoft', ?, ?, ?, 'pending', ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            tenant_identifier = VALUES(tenant_identifier),
            client_id = VALUES(client_id),
            client_secret_ciphertext = VALUES(client_secret_ciphertext),
            status = 'pending',
            oauth_state_hash = VALUES(oauth_state_hash),
            oauth_state_expires_at = VALUES(oauth_state_expires_at),
            code_verifier_ciphertext = VALUES(code_verifier_ciphertext),
            created_by = VALUES(created_by),
            last_error = NULL,
            updated_at = NOW()",
        [
            microsoft_mail_tenant_id(),
            $tenantIdentifier,
            $clientId,
            $storedClientSecret,
            $stateHash,
            // Checked later with PHP strtotime()/time(); MySQL NOW() may be UTC,
            // which made the state look expired as soon as it was stored.
            foxdesk_db_datetime(600),
            $verifierCiphertext,
            $createdBy,
        ]
    );
}

function microsoft_mail_begin_authorization(
    string $tenantIdentifier,
    string $clientId,
    string $clientSecret,
    ?int $createdBy = null,
    string $loginHint = ''
): string {
    $managedClientId = microsoft_mail_env('MICROSOFT_MAIL_CLIENT_ID');
    $managedClientSecret = microsoft_mail_env('MICROSOFT_MAIL_CLIENT_SECRET');
    $managedTenant = microsoft_mail_env('MICROSOFT_MAIL_TENANT_ID');
    if ($managedClientId !== '') {
        $clientId = $managedClientId;
    }
    if ($managedClientSecret !== '') {
        $clientSecret = $managedClientSecret;
    }
    if ($managedTenant !== '') {
        $tenantIdentifier = $managedTenant;
    }
    $tenantIdentifier = trim($tenantIdentifier) ?: 'common';
    $clientId = trim($clientId);
    if ($clientId === '' || trim($clientSecret) === '') {
        throw new InvalidArgumentException('Microsoft Client ID and Client Secret are required.');
    }

    $state = microsoft_mail_base64url(random_bytes(32));
    $verifier = microsoft_mail_base64url(random_bytes(64));
    $challenge = microsoft_mail_base64url(hash('sha256', $verifier, true));
    microsoft_mail_save_pending(
        $tenantIdentifier,
        $clientId,
        $clientSecret,
        hash('sha256', $state),
        (string) microsoft_mail_encrypt($verifier),
        $createdBy
    );

    $query = [
        'client_id' => $clientId,
        'response_type' => 'code',
        'redirect_uri' => microsoft_mail_redirect_uri(),
        'response_mode' => 'query',
        'scope' => implode(' ', microsoft_mail_scopes()),
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ];
    if (trim($loginHint) !== '') {
        $query['login_hint'] = trim($loginHint);
    }

    return microsoft_mail_authority($tenantIdentifier) . '/authorize?'
        . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function microsoft_mail_token_request(array $connection, array $payload): array
{
    $config = microsoft_mail_config($connection);
    if ($config['client_id'] === '' || $config['client_secret'] === '') {
        throw new RuntimeException('Microsoft OAuth application is not configured.');
    }
    $payload['client_id'] = $config['client_id'];
    $payload['client_secret'] = $config['client_secret'];
    $payload['scope'] = implode(' ', microsoft_mail_scopes());

    $response = microsoft_mail_http(
        microsoft_mail_authority($config['tenant_identifier']) . '/token',
        'POST',
        $payload,
        [],
        true
    );
    return microsoft_mail_assert_success($response, 'Microsoft OAuth');
}

function microsoft_mail_complete_authorization(string $state, string $code): array
{
    $connection = microsoft_mail_connection();
    $currentUserId = function_exists('current_user') ? (int) (current_user()['id'] ?? 0) : 0;
    if (!$connection
        || empty($connection['oauth_state_hash'])
        || !hash_equals((string) $connection['oauth_state_hash'], hash('sha256', trim($state)))
        || empty($connection['oauth_state_expires_at'])
        || strtotime((string) $connection['oauth_state_expires_at']) < time()
        || ((int) ($connection['created_by'] ?? 0) > 0 && (int) $connection['created_by'] !== $currentUserId)) {
        throw new RuntimeException('Microsoft authorization session is invalid or expired.');
    }
    if (trim($code) === '') {
        throw new RuntimeException('Microsoft did not return an authorization code.');
    }

    $verifier = microsoft_mail_decrypt((string) ($connection['code_verifier_ciphertext'] ?? ''));
    $tokens = microsoft_mail_token_request($connection, [
        'grant_type' => 'authorization_code',
        'code' => trim($code),
        'redirect_uri' => microsoft_mail_redirect_uri(),
        'code_verifier' => $verifier,
    ]);
    if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
        throw new RuntimeException('Microsoft did not return the required access and refresh tokens.');
    }

    $profileResponse = microsoft_mail_http(
        'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName,displayName',
        'GET',
        null,
        ['Authorization: Bearer ' . $tokens['access_token']]
    );
    $profile = microsoft_mail_assert_success($profileResponse, 'Microsoft profile');
    $mailbox = trim((string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
    if (!filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Microsoft account does not expose a usable mailbox address.');
    }

    $expiresAt = date('Y-m-d H:i:s', time() + max(60, (int) ($tokens['expires_in'] ?? 3600)));
    db_update('email_provider_connections', [
        'mailbox_email' => $mailbox,
        'access_token_ciphertext' => microsoft_mail_encrypt((string) $tokens['access_token']),
        'refresh_token_ciphertext' => microsoft_mail_encrypt((string) $tokens['refresh_token']),
        'token_expires_at' => $expiresAt,
        'scopes' => (string) ($tokens['scope'] ?? implode(' ', microsoft_mail_scopes())),
        'status' => 'active',
        'oauth_state_hash' => null,
        'oauth_state_expires_at' => null,
        'code_verifier_ciphertext' => null,
        'last_error' => null,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [(int) $connection['id']]);

    return ['mailbox_email' => $mailbox, 'display_name' => (string) ($profile['displayName'] ?? '')];
}

function microsoft_mail_access_token(bool $forceRefresh = false): string
{
    $connection = microsoft_mail_connection();
    if (!$connection || ($connection['status'] ?? '') !== 'active') {
        throw new RuntimeException('Microsoft mailbox is not connected.');
    }

    $expiresAt = strtotime((string) ($connection['token_expires_at'] ?? ''));
    if (!$forceRefresh && $expiresAt > time() + 120 && !empty($connection['access_token_ciphertext'])) {
        return microsoft_mail_decrypt((string) $connection['access_token_ciphertext']);
    }

    $refreshToken = microsoft_mail_decrypt((string) ($connection['refresh_token_ciphertext'] ?? ''));
    if ($refreshToken === '') {
        throw new RuntimeException('Microsoft refresh token is missing. Reconnect the mailbox.');
    }

    try {
        $tokens = microsoft_mail_token_request($connection, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
        if (empty($tokens['access_token'])) {
            throw new RuntimeException('Microsoft did not return a refreshed access token.');
        }
        $newRefresh = (string) ($tokens['refresh_token'] ?? $refreshToken);
        db_update('email_provider_connections', [
            'access_token_ciphertext' => microsoft_mail_encrypt((string) $tokens['access_token']),
            'refresh_token_ciphertext' => microsoft_mail_encrypt($newRefresh),
            'token_expires_at' => date('Y-m-d H:i:s', time() + max(60, (int) ($tokens['expires_in'] ?? 3600))),
            'scopes' => (string) ($tokens['scope'] ?? ($connection['scopes'] ?? '')),
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $connection['id']]);
        return (string) $tokens['access_token'];
    } catch (Throwable $e) {
        db_update('email_provider_connections', [
            'status' => 'reauthorization_required',
            'last_error' => mb_substr($e->getMessage(), 0, 1000),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $connection['id']]);
        throw $e;
    }
}

function microsoft_mail_graph_request(
    string $path,
    string $method = 'GET',
    ?array $payload = null,
    bool $retry = true
): array {
    $url = str_starts_with($path, 'https://graph.microsoft.com/')
        ? $path
        : 'https://graph.microsoft.com/v1.0/' . ltrim($path, '/');
    $token = microsoft_mail_access_token(false);
    $response = microsoft_mail_http($url, $method, $payload, ['Authorization: Bearer ' . $token]);
    if ((int) ($response['status'] ?? 0) === 401 && $retry) {
        $token = microsoft_mail_access_token(true);
        $response = microsoft_mail_http($url, $method, $payload, ['Authorization: Bearer ' . $token]);
    }
    return microsoft_mail_assert_success($response, 'Microsoft Graph');
}

function microsoft_mail_is_active(string $direction = ''): bool
{
    $connection = microsoft_mail_connection();
    if (!$connection || ($connection['status'] ?? '') !== 'active') {
        return false;
    }
    if ($direction === 'inbound') {
        return (int) ($connection['inbound_enabled'] ?? 1) === 1;
    }
    if ($direction === 'outbound') {
        return (int) ($connection['outbound_enabled'] ?? 1) === 1;
    }
    return true;
}

function microsoft_mail_status(): array
{
    $connection = microsoft_mail_connection();
    $config = microsoft_mail_config($connection);
    return [
        'configured' => $config['client_id'] !== '' && $config['client_secret'] !== '',
        'connected' => $connection && ($connection['status'] ?? '') === 'active',
        'status' => (string) ($connection['status'] ?? 'not_connected'),
        'mailbox_email' => (string) ($connection['mailbox_email'] ?? ''),
        'tenant_identifier' => (string) ($connection['tenant_identifier'] ?? $config['tenant_identifier']),
        'client_id' => (string) ($connection['client_id'] ?? $config['client_id']),
        'client_secret_set' => $config['client_secret'] !== '',
        'inbound_enabled' => (int) ($connection['inbound_enabled'] ?? 1) === 1,
        'outbound_enabled' => (int) ($connection['outbound_enabled'] ?? 1) === 1,
        'last_sync_at' => $connection['last_sync_at'] ?? null,
        'last_error' => (string) ($connection['last_error'] ?? ''),
        'redirect_uri' => microsoft_mail_redirect_uri(),
    ];
}

function microsoft_mail_set_directions(bool $inbound, bool $outbound): void
{
    $connection = microsoft_mail_connection();
    if (!$connection) {
        return;
    }
    db_update('email_provider_connections', [
        'inbound_enabled' => $inbound ? 1 : 0,
        'outbound_enabled' => $outbound ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [(int) $connection['id']]);
}

function microsoft_mail_disconnect(): void
{
    db_query(
        "DELETE FROM email_provider_connections WHERE tenant_id = ? AND provider = 'microsoft'",
        [microsoft_mail_tenant_id()]
    );
}

function microsoft_mail_test_connection(): array
{
    $profile = microsoft_mail_graph_request('me?$select=mail,userPrincipalName,displayName');
    return [
        'mailbox_email' => (string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? ''),
        'display_name' => (string) ($profile['displayName'] ?? ''),
    ];
}

function microsoft_mail_send(
    string $to,
    string $subject,
    string $body,
    bool $isHtml = false,
    string $replyTo = ''
): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid recipient email address is required.');
    }

    $message = [
        'subject' => trim((string) preg_replace('/[\r\n]+/', ' ', $subject)),
        'body' => [
            'contentType' => $isHtml ? 'HTML' : 'Text',
            'content' => $body,
        ],
        'toRecipients' => [
            ['emailAddress' => ['address' => $to]],
        ],
    ];
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $message['replyTo'] = [['emailAddress' => ['address' => $replyTo]]];
    }

    microsoft_mail_graph_request('me/sendMail', 'POST', [
        'message' => $message,
        'saveToSentItems' => true,
    ]);
    return true;
}

function microsoft_mail_list_unread(int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $query = http_build_query([
        '$top' => $limit,
        '$filter' => 'isRead eq false',
        '$select' => 'id,internetMessageId,subject,from,body,internetMessageHeaders,hasAttachments,receivedDateTime',
        '$orderby' => 'receivedDateTime asc',
    ], '', '&', PHP_QUERY_RFC3986);
    $result = microsoft_mail_graph_request('me/mailFolders/inbox/messages?' . $query);
    return is_array($result['value'] ?? null) ? $result['value'] : [];
}

function microsoft_mail_message_attachments(string $messageId): array
{
    $result = microsoft_mail_graph_request(
        'me/messages/' . rawurlencode($messageId) . '/attachments?$top=100'
    );
    return is_array($result['value'] ?? null) ? $result['value'] : [];
}

function microsoft_mail_finish_message(string $messageId, bool $success): void
{
    microsoft_mail_graph_request('me/messages/' . rawurlencode($messageId), 'PATCH', [
        'isRead' => true,
        'categories' => [$success ? 'FoxDesk Processed' : 'FoxDesk Failed'],
    ]);
}

function microsoft_mail_record_sync(?string $error = null): void
{
    $connection = microsoft_mail_connection();
    if (!$connection) {
        return;
    }
    db_update('email_provider_connections', [
        'last_sync_at' => date('Y-m-d H:i:s'),
        'last_error' => $error === null ? null : mb_substr($error, 0, 1000),
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [(int) $connection['id']]);
}
