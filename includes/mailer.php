<?php
/**
 * Mailer Functions
 * 
 * Simple mailer for notifications with SMTP and PHP mail() fallback
 */

/**
 * Get base URL for the application
 */
function get_app_url()
{
    if (defined('APP_URL') && !empty(APP_URL)) {
        return rtrim(APP_URL, '/');
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '');

    return $protocol . '://' . $host . $path;
}

/**
 * Send email using configured method
 */
function send_email($to, $subject, $body, $is_html = false, $force_delivery = false)
{
    $settings = get_settings();

    // Check if email notifications are enabled
    if (!$force_delivery && (empty($settings['email_notifications_enabled']) || $settings['email_notifications_enabled'] !== '1')) {
        error_log("Email notifications disabled, skipping: $to - $subject");
        return false;
    }

    // Respect per-user email preference for user/agent roles unless this is a forced system message.
    if (!$force_delivery) {
        $recipient_user = db_fetch_one("SELECT * FROM users WHERE email = ? LIMIT 1", [$to]);
        if ($recipient_user) {
            $allowed = function_exists('user_email_notifications_enabled')
                ? user_email_notifications_enabled($recipient_user)
                : ((int) ($recipient_user['email_notifications_enabled'] ?? 1) === 1);
            if (!$allowed) {
                error_log("Recipient opted out of email notifications, skipping: $to - $subject");
                return false;
            }
        }
    }

    $from_email = $settings['smtp_from_email'] ?? '';
    $from_name = $settings['smtp_from_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');

    // If no from email, use admin email
    if (empty($from_email)) {
        $admin = db_fetch_one("SELECT email FROM users WHERE role = 'admin' LIMIT 1");
        $from_email = $admin['email'] ?? 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    $smtp_host = $settings['smtp_host'] ?? '';
    $smtp_user = $settings['smtp_user'] ?? '';

    // If SMTP is configured, use SMTP
    if (!empty($smtp_host) && !empty($smtp_user)) {
        $result = send_smtp_email($to, $subject, $body, $is_html, [
            'host' => $smtp_host,
            'port' => (int) ($settings['smtp_port'] ?? 587),
            'user' => $smtp_user,
            'pass' => $settings['smtp_pass'] ?? '',
            'encryption' => $settings['smtp_encryption'] ?? 'tls',
            'from_email' => $from_email,
            'from_name' => $from_name
        ]);

        if ($result) {
            error_log("Email sent via SMTP to: $to");
            return true;
        }
        error_log("SMTP failed, trying PHP mail()");
    }

    // Fallback to PHP mail()
    return send_php_mail($to, $subject, $body, $is_html, $from_email, $from_name);
}

/**
 * Send email via PHP mail() function
 */
function send_php_mail($to, $subject, $body, $is_html, $from_email, $from_name)
{
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = $is_html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'From: =?UTF-8?B?' . base64_encode($from_name) . '?= <' . $from_email . '>';
    $headers[] = 'Reply-To: ' . $from_email;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    // Encode subject for UTF-8
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $result = @mail($to, $encoded_subject, $body, implode("\r\n", $headers));

    if ($result) {
        error_log("Email sent via PHP mail() to: $to");
    } else {
        error_log("PHP mail() failed for: $to");
    }

    return $result;
}

/**
 * Send email via SMTP socket
 */
function send_smtp_email($to, $subject, $body, $is_html, $config)
{
    $socket = null;

    try {
        $host = $config['host'];
        $port = $config['port'];
        $user = $config['user'];
        $pass = $config['pass'];
        $encryption = $config['encryption'];
        $from_email = $config['from_email'];
        $from_name = $config['from_name'];

        // Connect to SMTP server
        $socket_host = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $socket = @fsockopen($socket_host, $port, $errno, $errstr, 30);

        if (!$socket) {
            error_log("SMTP connection failed: $errstr ($errno)");
            return false;
        }

        // Set timeout
        stream_set_timeout($socket, 30);

        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '220') {
            error_log("SMTP unexpected response: $response");
            fclose($socket);
            return false;
        }

        // EHLO
        fputs($socket, "EHLO " . gethostname() . "\r\n");
        $response = '';
        while ($str = fgets($socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) === ' ')
                break;
        }

        // STARTTLS for TLS
        if ($encryption === 'tls') {
            fputs($socket, "STARTTLS\r\n");
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) === '220') {
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    error_log("SMTP TLS negotiation failed");
                    fclose($socket);
                    return false;
                }

                // Send EHLO again after STARTTLS
                fputs($socket, "EHLO " . gethostname() . "\r\n");
                while ($str = fgets($socket, 515)) {
                    if (substr($str, 3, 1) === ' ')
                        break;
                }
            }
        }

        // AUTH LOGIN
        fputs($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 515);

        if (substr($response, 0, 3) !== '334') {
            error_log("SMTP AUTH not supported: $response");
            fclose($socket);
            return false;
        }

        fputs($socket, base64_encode($user) . "\r\n");
        $response = fgets($socket, 515);

        fputs($socket, base64_encode($pass) . "\r\n");
        $response = fgets($socket, 515);

        if (substr($response, 0, 3) !== '235') {
            error_log("SMTP authentication failed: $response");
            fclose($socket);
            return false;
        }

        // MAIL FROM
        fputs($socket, "MAIL FROM:<{$from_email}>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '250') {
            error_log("SMTP MAIL FROM failed: $response");
            fclose($socket);
            return false;
        }

        // RCPT TO
        fputs($socket, "RCPT TO:<{$to}>\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '250' && substr($response, 0, 3) !== '251') {
            error_log("SMTP RCPT TO failed: $response");
            fclose($socket);
            return false;
        }

        // DATA
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '354') {
            error_log("SMTP DATA failed: $response");
            fclose($socket);
            return false;
        }

        // Build message
        $content_type = $is_html ? "text/html" : "text/plain";
        $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encoded_from = '=?UTF-8?B?' . base64_encode($from_name) . '?= <' . $from_email . '>';

        $message = "Date: " . date('r') . "\r\n";
        $message .= "To: {$to}\r\n";
        $message .= "From: {$encoded_from}\r\n";
        $message .= "Subject: {$encoded_subject}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: {$content_type}; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n";
        $message .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $message .= "\r\n";
        $message .= $body;
        $message .= "\r\n.\r\n";

        fputs($socket, $message);
        $response = fgets($socket, 515);

        // QUIT
        fputs($socket, "QUIT\r\n");
        fclose($socket);

        return substr($response, 0, 3) === '250';

    } catch (Exception $e) {
        error_log("SMTP error: " . $e->getMessage());
        if ($socket) {
            @fclose($socket);
        }
        return false;
    }
}

/**
 * Send password reset email (always sends, ignores notification settings)
 */
function send_password_reset_email($to, $name, $reset_link)
{
    // Password reset is special - we might not have a user ID if they forgot it, 
    // but usually we do. However, this function signature doesn't pass user object.
    // We'll try to find the user by email to get their language.
    $user = db_fetch_one("SELECT * FROM users WHERE email = ?", [$to]);
    $lang = $user['language'] ?? 'en';

    $template = get_email_template('password_reset', $lang);

    if (!$template) {
        $subject = 'Password reset';
        $body = "Hello,\n\nYou requested a password reset. Click the link below:\n{$reset_link}\n\nThis link is valid for 1 hour.\n\nIf you did not request a password reset, please ignore this email.";
    } else {
        $subject = $template['subject'];
        $body = $template['body'];
    }

    $settings = get_settings();
    $app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');

    // Replace placeholders
    $body = str_replace('{reset_link}', $reset_link, $body);
    $body = str_replace('{name}', $name, $body);
    $body = str_replace('{app_name}', $app_name, $body);
    $subject = str_replace('{app_name}', $app_name, $subject);

    $result = send_email($to, $subject, $body, false, true);

    return $result;
}

/**
 * Send status change notification
 */
function send_status_change_notification($ticket, $old_status, $new_status, $comment_text = '', $time_spent = 0)
{
    $settings = get_settings();

    if (empty($settings['notify_on_status_change']) || $settings['notify_on_status_change'] !== '1') {
        return false;
    }

    $user = get_user($ticket['user_id']);
    if (!$user)
        return false;

    $lang = $user['language'] ?? 'en';
    $template = get_email_template('status_change', $lang);
    $app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');
    $ticket_url = get_app_url() . '/index.php?page=ticket&id=' . $ticket['id'];
    $recipient_name = $user['first_name'] . ' ' . $user['last_name'];

    // Format time spent
    $time_spent_text = '';
    if ($time_spent > 0) {
        $hours = floor($time_spent / 60);
        $mins = $time_spent % 60;
        if ($hours > 0) {
            $time_spent_text = $hours . 'h ' . $mins . 'min';
        } else {
            $time_spent_text = $mins . ' min';
        }
    }

    if (!$template) {
        $subject = 'Status changed for ticket #{ticket_id}: {ticket_title}';
        $body = "Hello,\n\nThe status of your ticket \"{ticket_title}\" has changed.\n\n";
        $body .= "Previous status: {old_status}\n";
        $body .= "New status: {new_status}\n";
        if ($time_spent_text)
            $body .= "Time spent: {time_spent}\n";
        if ($comment_text)
            $body .= "\nComment:\n---\n{comment_text}\n---\n";
        $body .= "\nView ticket: {ticket_url}\n\n";
        $body .= "Regards,\n{app_name}";
    } else {
        $subject = $template['subject'];
        $body = $template['body'];
    }

    $ticket_code = get_ticket_code($ticket['id']);

    // Replace all placeholders in subject
    $subject = str_replace('{ticket_id}', $ticket_code, $subject);
    $subject = str_replace('{ticket_title}', $ticket['title'], $subject);
    $subject = str_replace('{new_status}', $new_status['name'], $subject);
    $subject = str_replace('{app_name}', $app_name, $subject);

    // Replace all placeholders in body
    $replacements = [
        '{ticket_id}' => $ticket_code,
        '{ticket_title}' => $ticket['title'],
        '{old_status}' => $old_status['name'],
        '{new_status}' => $new_status['name'],
        '{comment_text}' => $comment_text ?: '',
        '{time_spent}' => $time_spent_text !== '' ? $time_spent_text : get_email_template_phrase('not_provided', $lang),
        '{ticket_url}' => $ticket_url,
        '{app_name}' => $app_name,
        '{recipient_name}' => $recipient_name,
        '{name}' => $recipient_name
    ];

    foreach ($replacements as $key => $value) {
        $body = str_replace($key, $value, $body);
    }

    return send_email($user['email'], $subject, $body, false);
}

/**
 * Send new comment notification with comment text, time, attachments, and direct link
 */
function send_new_comment_notification($ticket, $comment, $commenter, $comment_id = null, $attachments = [], $cc_user_ids = [])
{
    $settings = get_settings();

    if (empty($settings['notify_on_new_comment']) || $settings['notify_on_new_comment'] !== '1') {
        return false;
    }

    $template_key = 'new_comment';
    $app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');
    $ticket_code = get_ticket_code($ticket['id']);

    $ticket_url = get_app_url() . '/index.php?page=ticket&id=' . $ticket['id'];
    $comment_url = $ticket_url;
    if ($comment_id) {
        $comment_url .= '#comment-' . $comment_id;
    }

    $comment_text = $comment['content'] ?? '';
    $commenter_name = trim(($commenter['first_name'] ?? '') . ' ' . ($commenter['last_name'] ?? ''));
    if ($commenter_name === '') {
        $commenter_name = $commenter['email'] ?? 'System';
    }

    $time_spent = '';
    if (!empty($comment['time_spent']) && $comment['time_spent'] > 0) {
        $hours = floor($comment['time_spent'] / 60);
        $mins = $comment['time_spent'] % 60;
        if ($hours > 0) {
            $time_spent = $hours . 'h ' . $mins . 'min';
        } else {
            $time_spent = $mins . ' min';
        }
    }

    $attachments_text = '';
    if (!empty($attachments)) {
        $attachment_names = [];
        foreach ($attachments as $att) {
            $name = (string) ($att['original_name'] ?? $att['filename'] ?? '');
            if ($name !== '') {
                $attachment_names[] = $name;
            }
        }
        $attachments_text = implode(', ', $attachment_names);
    }

    $recipients = [];

    if ($commenter['id'] != $ticket['user_id']) {
        $recipients[$ticket['user_id']] = ['type' => 'owner'];
    }

    foreach ($cc_user_ids as $cc_id) {
        $cc_id = (int) $cc_id;
        if ($cc_id > 0 && $cc_id != $commenter['id']) {
            if (!isset($recipients[$cc_id])) {
                $recipients[$cc_id] = ['type' => 'cc'];
            }
        }
    }

    $any_sent = false;
    $all_ok = true;

    foreach ($recipients as $uid => $meta) {
        $recipient = get_user($uid);
        if (!$recipient || empty($recipient['email'])) {
            continue;
        }

        $lang = normalize_email_template_language($recipient['language'] ?? 'en');
        $template = get_email_template($template_key, $lang);
        $recipient_name = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''));
        if ($recipient_name === '') {
            $recipient_name = $recipient['email'];
        }

        if (!$template) {
            $subject = 'New comment on ticket #{ticket_id}: {ticket_title}';
            $body = "Hello,\n\nA new comment was added to your ticket \"{ticket_title}\".\n\n";
            $body .= "From: {commenter_name}\n";
            if ($time_spent) {
                $body .= "Time spent: {time_spent}\n";
            }
            if ($attachments_text) {
                $body .= "Attachments: {attachments}\n";
            }
            $body .= "\n---\n{comment_text}\n---\n\n";
            $body .= "View comment: {comment_url}\n\n";
            $body .= "Regards,\n{app_name}";
        } else {
            $subject = $template['subject'];
            $body = $template['body'];
        }

        if (($meta['type'] ?? '') === 'cc') {
            $subject = '[CC] ' . $subject;
        }

        $replacements = [
            '{ticket_id}' => $ticket_code,
            '{ticket_title}' => $ticket['title'] ?? '',
            '{comment_text}' => $comment_text,
            '{comment}' => $comment_text,
            '{commenter_name}' => $commenter_name,
            '{commenter}' => $commenter_name,
            '{time_spent}' => $time_spent !== '' ? $time_spent : get_email_template_phrase('not_provided', $lang),
            '{attachments}' => $attachments_text !== '' ? $attachments_text : get_email_template_phrase('none', $lang),
            '{ticket_url}' => $ticket_url,
            '{comment_url}' => $comment_url,
            '{app_name}' => $app_name,
            '{recipient_name}' => $recipient_name,
            '{name}' => $recipient_name
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subject);
        $body = str_replace(array_keys($replacements), array_values($replacements), $body);

        if (send_email($recipient['email'], $subject, $body, false)) {
            $any_sent = true;
        } else {
            $all_ok = false;
        }
    }

    if (!$any_sent) {
        return false;
    }

    return $all_ok;
}

/**
 * Send new ticket notification to admins
 */
function send_new_ticket_notification($ticket)
{
    $settings = get_settings();

    if (empty($settings['notify_on_new_ticket']) || $settings['notify_on_new_ticket'] !== '1') {
        return false;
    }

    // Admins might have different languages. We need to send individually or group by language.
    // For simplicity, we'll iterate admins.

    $app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');
    $ticket_url = get_app_url() . '/index.php?page=ticket&id=' . $ticket['id'];

    // Get ticket type label
    $type_label = get_type_label($ticket['type'] ?? 'general');

    // Get priority info
    $priority_name = $ticket['priority_name'] ?? 'Medium';

    // Get user info
    $user = get_user($ticket['user_id']);
    $user_name = $user ? ($user['first_name'] . ' ' . $user['last_name']) : 'Unknown';
    $user_email = $user ? $user['email'] : '';

    // Get all admins
    $admins = db_fetch_all("SELECT id, email, language, first_name, last_name FROM users WHERE role = 'admin' AND is_active = 1");
    if (empty($admins)) {
        return false;
    }

    $result = true;
    foreach ($admins as $admin) {
        $lang = $admin['language'] ?? 'en';
        $template = get_email_template('new_ticket', $lang);
        if (!$template) {
            $subject = 'New ticket #{ticket_id}: {ticket_title}';
            $body = "Hello,\n\nA new ticket has been created.\n\n";
            $body .= "Subject: {ticket_title}\n";
            $body .= "Type: {ticket_type}\n";
            $body .= "Priority: {priority}\n";
            $body .= "From: {user_name} ({user_email})\n";
            if (!empty($ticket['description']))
                $body .= "\nDescription:\n---\n{description}\n---\n";
            $body .= "\nView ticket: {ticket_url}\n\n";
            $body .= "Regards,\n{app_name}";
        } else {
            $subject = $template['subject'];
            $body = $template['body'];
        }

        $ticket_code = get_ticket_code($ticket['id']);

        // Replace placeholders
        $subject = str_replace('{ticket_id}', $ticket_code, $subject);
        $subject = str_replace('{ticket_title}', $ticket['title'], $subject);
        $subject = str_replace('{app_name}', $app_name, $subject);

        $replacements = [
            '{ticket_id}' => $ticket_code,
            '{ticket_title}' => $ticket['title'],
            '{ticket_type}' => $type_label,
            '{priority}' => $priority_name,
            '{user_name}' => $user_name,
            '{user_email}' => $user_email,
            '{description}' => $ticket['description'] ?? '',
            '{ticket_url}' => $ticket_url,
            '{app_name}' => $app_name,
            '{recipient_name}' => $admin['first_name'] . ' ' . $admin['last_name'],
            '{name}' => $admin['first_name'] . ' ' . $admin['last_name']
        ];

        foreach ($replacements as $key => $value) {
            $body = str_replace($key, $value, $body);
        }

        if (!send_email($admin['email'], $subject, $body, false)) {
            $result = false;
        }
    }

    return $result;
}

/**
 * Ensure email_templates table supports language-specific records.
 * Handles legacy schemas that still use UNIQUE(template_key).
 */
function ensure_email_templates_language_schema()
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $has_language = db_fetch_one("SHOW COLUMNS FROM email_templates LIKE 'language'");
        if (!$has_language) {
            db_query("ALTER TABLE email_templates ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT 'en' AFTER template_key");
        }

        $indexes = db_fetch_all("SHOW INDEX FROM email_templates");
        $unique_indexes = [];

        foreach ($indexes as $index_row) {
            if ((int) ($index_row['Non_unique'] ?? 1) !== 0) {
                continue;
            }

            $index_name = (string) ($index_row['Key_name'] ?? '');
            $seq = (int) ($index_row['Seq_in_index'] ?? 0);
            $column_name = (string) ($index_row['Column_name'] ?? '');
            if ($index_name === '' || $seq <= 0 || $column_name === '') {
                continue;
            }

            $unique_indexes[$index_name][$seq] = $column_name;
        }

        $has_key_lang_unique = false;
        $single_key_unique_indexes = [];

        foreach ($unique_indexes as $index_name => $columns_by_seq) {
            ksort($columns_by_seq);
            $columns = array_values($columns_by_seq);

            if ($columns === ['template_key', 'language']) {
                $has_key_lang_unique = true;
            } elseif ($columns === ['template_key']) {
                $single_key_unique_indexes[] = $index_name;
            }
        }

        if (!$has_key_lang_unique) {
            foreach ($single_key_unique_indexes as $index_name) {
                $safe_index_name = str_replace('`', '', $index_name);
                db_query("ALTER TABLE email_templates DROP INDEX `{$safe_index_name}`");
            }

            db_query("ALTER TABLE email_templates ADD UNIQUE KEY uniq_key_lang (template_key, language)");
        }
    } catch (Throwable $e) {
        // Non-fatal: keep mailer working even if schema cannot be modified.
    }
}

/**
 * Normalize e-mail template language code.
 */
function normalize_email_template_language($lang)
{
    $allowed = ['en', 'cs', 'de', 'it', 'es'];
    $lang = strtolower(trim((string) $lang));
    return in_array($lang, $allowed, true) ? $lang : 'en';
}

/**
 * Localized fallback phrase used in template replacements.
 */
function get_email_template_phrase($key, $lang = 'en')
{
    $lang = normalize_email_template_language($lang);
    $phrases = [
        'not_provided' => [
            'en' => 'not provided',
            'cs' => 'neuvedeno',
            'de' => 'nicht angegeben',
            'it' => 'non specificato',
            'es' => 'no especificado'
        ],
        'none' => [
            'en' => 'none',
            'cs' => 'žádné',
            'de' => 'keine',
            'it' => 'nessuno',
            'es' => 'ninguno'
        ]
    ];

    if (!isset($phrases[$key])) {
        return '';
    }

    return $phrases[$key][$lang] ?? $phrases[$key]['en'];
}

/**
 * Built-in default templates used as fallback when DB template for recipient language is missing.
 */
function get_builtin_email_template($key, $lang = 'en')
{
    $lang = normalize_email_template_language($lang);

    $templates = [
        'status_change' => [
            'en' => [
                'subject' => 'Status changed for ticket #{ticket_id}: {ticket_title}',
                'body' => "Hello,\n\nThe status of your ticket \"{ticket_title}\" has changed.\n\nPrevious status: {old_status}\nNew status: {new_status}\n\nComment:\n{comment_text}\n\nTime spent: {time_spent}\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
            ],
            'cs' => [
                'subject' => 'Stav změněn u požadavku #{ticket_id}: {ticket_title}',
                'body' => "Dobrý den,\n\nStav vašeho požadavku \"{ticket_title}\" byl změněn.\n\nPředchozí stav: {old_status}\nNový stav: {new_status}\n\nKomentář:\n{comment_text}\n\nStrávený čas: {time_spent}\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n{app_name}"
            ]
        ],
        'new_comment' => [
            'en' => [
                'subject' => 'New comment on ticket #{ticket_id}: {ticket_title}',
                'body' => "Hello,\n\nA new comment was added to your ticket \"{ticket_title}\".\n\nFrom: {commenter_name}\nTime spent: {time_spent}\nAttachments: {attachments}\n\n---\n{comment_text}\n---\n\nView comment: {comment_url}\n\nRegards,\n{app_name}"
            ],
            'cs' => [
                'subject' => 'Nový komentář u požadavku #{ticket_id}: {ticket_title}',
                'body' => "Dobrý den,\n\nK vašemu požadavku \"{ticket_title}\" byl přidán nový komentář.\n\nOd: {commenter_name}\nStrávený čas: {time_spent}\nPřílohy: {attachments}\n\n---\n{comment_text}\n---\n\nZobrazit komentář: {comment_url}\n\nS pozdravem,\n{app_name}"
            ]
        ],
        'new_ticket' => [
            'en' => [
                'subject' => 'New ticket #{ticket_id}: {ticket_title}',
                'body' => "Hello,\n\nA new ticket has been created.\n\nSubject: {ticket_title}\nType: {ticket_type}\nPriority: {priority}\nFrom: {user_name} ({user_email})\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
            ],
            'cs' => [
                'subject' => 'Nový požadavek #{ticket_id}: {ticket_title}',
                'body' => "Dobrý den,\n\nByl vytvořen nový požadavek.\n\nPředmět: {ticket_title}\nTyp: {ticket_type}\nPriorita: {priority}\nOd: {user_name} ({user_email})\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n{app_name}"
            ]
        ],
        'password_reset' => [
            'en' => [
                'subject' => 'Password reset',
                'body' => "Hello,\n\nYou requested a password reset. Click the link below:\n{reset_link}\n\nThis link is valid for 1 hour.\n\nIf you did not request a password reset, please ignore this email.\n\nRegards,\n{app_name}"
            ],
            'cs' => [
                'subject' => 'Obnovení hesla',
                'body' => "Dobrý den,\n\nPožádali jste o obnovení hesla. Klikněte na odkaz níže:\n{reset_link}\n\nTento odkaz je platný 1 hodinu.\n\nPokud jste o obnovení hesla nežádali, ignorujte tento e-mail.\n\nS pozdravem,\n{app_name}"
            ]
        ],
        'ticket_confirmation' => [
            'en' => [
                'subject' => 'Ticket received #{ticket_code}: {ticket_title}',
                'body' => "Hello,\n\nYour ticket #{ticket_code} \"{ticket_title}\" was received successfully.\nWe will keep you updated on its progress.\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
            ],
            'cs' => [
                'subject' => 'Požadavek přijat #{ticket_code}: {ticket_title}',
                'body' => "Dobrý den,\n\nVáš požadavek #{ticket_code} \"{ticket_title}\" jsme úspěšně přijali.\nO jeho průběhu vás budeme informovat.\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n{app_name}"
            ]
        ],
        'ticket_assignment' => [
            'en' => [
                'subject' => 'Ticket assigned #{ticket_code}: {ticket_title}',
                'body' => "Hello {agent_name},\n\nYou have been assigned a ticket to handle:\n\nTicket: #{ticket_code}\nSubject: {ticket_title}\nAssigned by: {assigner_name}\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
            ],
            'cs' => [
                'subject' => 'Přiřazen požadavek #{ticket_code}: {ticket_title}',
                'body' => "Dobrý den {agent_name},\n\nByl vám přiřazen požadavek k řešení:\n\nPožadavek: #{ticket_code}\nPředmět: {ticket_title}\nPřiřadil: {assigner_name}\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n{app_name}"
            ]
        ],
        'recurring_task_assignment' => [
            'en' => [
                'subject' => 'New recurring task assigned: {ticket_title}',
                'body' => "Hello {recipient_name},\n\nA recurring task generated a new ticket for you.\n\nTicket: #{ticket_code}\nTitle: {ticket_title}\nDescription: {ticket_description}\nDue date: {due_date}\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
            ],
            'cs' => [
                'subject' => 'Přiřazen nový opakující se úkol: {ticket_title}',
                'body' => "Dobrý den {recipient_name},\n\nOpakující se úkol pro vás vytvořil nový požadavek.\n\nPožadavek: #{ticket_code}\nNázev: {ticket_title}\nPopis: {ticket_description}\nTermín: {due_date}\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n{app_name}"
            ]
        ],
        'long_timer_alert' => [
            'en' => [
                'subject' => 'Timer Running Too Long - Ticket #{ticket_code}',
                'body' => "Dear {user_name},\n\nYour time tracker has been running for {elapsed_time} on ticket \"{ticket_title}\".\n\nStarted at: {started_at}\nTicket: #{ticket_code} - {ticket_title}\n\nPlease check if you forgot to stop the timer.\n\nView ticket: {ticket_url}\n\nSincerely,\n\nThe {app_name} Team"
            ],
            'cs' => [
                'subject' => 'Časovač běží příliš dlouho - Požadavek #{ticket_code}',
                'body' => "Dobrý den,\n\nVáš časovač běží již {elapsed_time} na požadavku \"{ticket_title}\".\n\nSpuštěn v: {started_at}\nPožadavek: #{ticket_code} - {ticket_title}\n\nProsím zkontrolujte, zda jste nezapomněli zastavit časovač.\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n\nTým {app_name}"
            ],
            'de' => [
                'subject' => 'Timer läuft zu lange - Ticket #{ticket_code}',
                'body' => "Guten Tag,\n\nIhr Zeiterfassungs-Timer läuft bereits {elapsed_time} für das Ticket \"{ticket_title}\".\n\nGestartet um: {started_at}\nTicket: #{ticket_code} - {ticket_title}\n\nBitte überprüfen Sie, ob Sie vergessen haben, den Timer zu stoppen.\n\nTicket anzeigen: {ticket_url}\n\nMit freundlichen Grüßen,\n\nIhr {app_name} Team"
            ],
            'it' => [
                'subject' => 'Timer in esecuzione troppo a lungo - Ticket #{ticket_code}',
                'body' => "Buongiorno,\n\nIl suo timer sta girando da {elapsed_time} sul ticket \"{ticket_title}\".\n\nAvviato alle: {started_at}\nTicket: #{ticket_code} - {ticket_title}\n\nPer favore verifichi se ha dimenticato di fermare il timer.\n\nVisualizza ticket: {ticket_url}\n\nCordiali saluti,\n\nIl team {app_name}"
            ],
            'es' => [
                'subject' => 'Temporizador funcionando demasiado tiempo - Ticket #{ticket_code}',
                'body' => "Estimado/a {user_name},\n\nSu temporizador ha estado funcionando durante {elapsed_time} en el ticket \"{ticket_title}\".\n\nIniciado a las: {started_at}\nTicket: #{ticket_code} - {ticket_title}\n\nPor favor, compruebe si olvidó detener el temporizador.\n\nVer ticket: {ticket_url}\n\nAtentamente,\n\nEl equipo de {app_name}"
            ]
        ],
        'welcome_email' => [
            'en' => [
                'subject' => 'Welcome to {app_name}',
                'body' => "Hello {name},\n\nYour account has been created.\n\nEmail: {email}\nPassword: {password}\n\nLogin: {login_url}\n\nAfter signing in, you can change your password in your profile settings.\n\nRegards,\n{app_name}"
            ],
            'cs' => [
                'subject' => 'Vítejte v {app_name}',
                'body' => "Dobrý den {name},\n\nVáš účet byl vytvořen.\n\nEmail: {email}\nHeslo: {password}\n\nPřihlášení: {login_url}\n\nPo přihlášení si můžete změnit heslo v nastavení profilu.\n\nS pozdravem,\n{app_name}"
            ],
            'de' => [
                'subject' => 'Willkommen bei {app_name}',
                'body' => "Hallo {name},\n\nIhr Konto wurde erstellt.\n\nE-Mail: {email}\nPasswort: {password}\n\nAnmeldung: {login_url}\n\nNach der Anmeldung können Sie Ihr Passwort in Ihren Profileinstellungen ändern.\n\nMit freundlichen Grüßen,\n{app_name}"
            ],
            'it' => [
                'subject' => 'Benvenuto in {app_name}',
                'body' => "Ciao {name},\n\nIl tuo account è stato creato.\n\nEmail: {email}\nPassword: {password}\n\nAccesso: {login_url}\n\nDopo aver effettuato l'accesso, puoi modificare la password nelle impostazioni del profilo.\n\nCordiali saluti,\n{app_name}"
            ],
            'es' => [
                'subject' => 'Bienvenido a {app_name}',
                'body' => "Hola {name},\n\nSu cuenta ha sido creada.\n\nCorreo electrónico: {email}\nContraseña: {password}\n\nIniciar sesión: {login_url}\n\nDespués de iniciar sesión, puede cambiar su contraseña en la configuración de su perfil.\n\nSaludos,\n{app_name}"
            ]
        ]
    ];

    if (empty($templates[$key])) {
        return null;
    }

    if (!empty($templates[$key][$lang])) {
        return [
            'template_key' => $key,
            'language' => $lang,
            'subject' => $templates[$key][$lang]['subject'],
            'body' => $templates[$key][$lang]['body'],
            'is_active' => 1
        ];
    }

    if (!empty($templates[$key]['en'])) {
        return [
            'template_key' => $key,
            'language' => 'en',
            'subject' => $templates[$key]['en']['subject'],
            'body' => $templates[$key]['en']['body'],
            'is_active' => 1
        ];
    }

    return null;
}

/**
 * Get email template
 */
function get_email_template($key, $lang = 'en')
{
    try {
        ensure_email_templates_language_schema();
        $lang = normalize_email_template_language($lang);

        $template = db_fetch_one("SELECT * FROM email_templates WHERE template_key = ? AND language = ? AND is_active = 1", [$key, $lang]);
        if ($template) {
            return $template;
        }

        $builtin = get_builtin_email_template($key, $lang);
        if ($builtin) {
            return $builtin;
        }

        if ($lang !== 'en') {
            $template = db_fetch_one("SELECT * FROM email_templates WHERE template_key = ? AND language = 'en' AND is_active = 1", [$key]);
            if ($template) {
                return $template;
            }
        }

        $builtin = get_builtin_email_template($key, 'en');
        if ($builtin) {
            return $builtin;
        }

        return db_fetch_one("SELECT * FROM email_templates WHERE template_key = ? AND is_active = 1 LIMIT 1", [$key]);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Save email template
 */
function save_email_template($key, $subject, $body, $lang = 'en')
{
    ensure_email_templates_language_schema();

    $existing = db_fetch_one("SELECT id FROM email_templates WHERE template_key = ? AND language = ?", [$key, $lang]);

    if ($existing) {
        db_update('email_templates', [
            'subject' => $subject,
            'body' => $body
        ], 'id = ?', [$existing['id']]);
    } else {
        db_insert('email_templates', [
            'template_key' => $key,
            'language' => $lang,
            'subject' => $subject,
            'body' => $body,
            'is_active' => 1
        ]);
    }
}

/**
 * Test SMTP connection
 */
function test_smtp_connection($config)
{
    $socket = null;

    try {
        if (empty($config['host'])) {
            return ['success' => false, 'message' => 'SMTP server is not configured.'];
        }

        $socket_host = ($config['encryption'] === 'ssl') ? 'ssl://' . $config['host'] : $config['host'];
        $socket = @fsockopen($socket_host, $config['port'], $errno, $errstr, 10);

        if (!$socket) {
            return ['success' => false, 'message' => "Unable to connect to {$config['host']}:{$config['port']} - $errstr ($errno)"];
        }

        stream_set_timeout($socket, 10);

        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'message' => "Unexpected server response: " . trim($response)];
        }

        // EHLO
        fputs($socket, "EHLO " . gethostname() . "\r\n");
        while ($str = fgets($socket, 515)) {
            if (substr($str, 3, 1) === ' ')
                break;
        }

        // STARTTLS for TLS
        if ($config['encryption'] === 'tls') {
            fputs($socket, "STARTTLS\r\n");
            $response = fgets($socket, 515);
            if (substr($response, 0, 3) === '220') {
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($socket);
                    return ['success' => false, 'message' => 'TLS negotiation failed.'];
                }
                fputs($socket, "EHLO " . gethostname() . "\r\n");
                while ($str = fgets($socket, 515)) {
                    if (substr($str, 3, 1) === ' ')
                        break;
                }
            }
        }

        // AUTH LOGIN
        if (!empty($config['user'])) {
            fputs($socket, "AUTH LOGIN\r\n");
            $response = fgets($socket, 515);

            if (substr($response, 0, 3) !== '334') {
                fclose($socket);
                return ['success' => false, 'message' => 'Server does not support AUTH LOGIN.'];
            }

            fputs($socket, base64_encode($config['user']) . "\r\n");
            $response = fgets($socket, 515);

            fputs($socket, base64_encode($config['pass']) . "\r\n");
            $response = fgets($socket, 515);

            if (substr($response, 0, 3) !== '235') {
                fclose($socket);
                return ['success' => false, 'message' => 'Authentication failed. Check username and password.'];
            }
        }

        fputs($socket, "QUIT\r\n");
        fclose($socket);

        return ['success' => true, 'message' => 'SMTP connection successful.'];

    } catch (Exception $e) {
        if ($socket) {
            @fclose($socket);
        }
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Send ticket confirmation to user
 */
function send_ticket_confirmation_to_user($ticket)
{
    $settings = get_settings();

    // Get user to determine language
    $user = get_user($ticket['user_id']);
    if (!$user) {
        return;
    }

    $lang = $user['language'] ?? 'en';
    $app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');
    $ticket_code = get_ticket_code($ticket['id']);
    $ticket_url = get_app_url() . '/index.php?page=ticket&id=' . $ticket['id'];
    $recipient_name = $user['first_name'] . ' ' . $user['last_name'];

    // Get template
    $template = get_email_template('ticket_confirmation', $lang);

    if (!$template) {
        // Fallback
        $subject = 'Ticket Received: {ticket_title}';
        $body = "Dear {recipient_name},\n\nWe have received your ticket.\n\nID: #{ticket_code}\nSubject: {ticket_title}\n\nYou can track the progress here: {ticket_url}\n\nRegards,\n{app_name}";
    } else {
        $subject = $template['subject'];
        $body = $template['body'];
    }

    // Replace placeholders
    $placeholders = [
        '{ticket_id}' => $ticket['id'],
        '{ticket_code}' => $ticket_code,
        '{ticket_title}' => $ticket['title'],
        '{ticket_url}' => $ticket_url,
        '{app_name}' => $app_name,
        '{recipient_name}' => $recipient_name,
        '{name}' => $recipient_name
    ];

    foreach ($placeholders as $key => $value) {
        $subject = str_replace($key, $value, $subject);
        $body = str_replace($key, $value, $body);
    }

    send_email($user['email'], $subject, $body);
}

/**
 * Send ticket assignment notification to agent
 */
function send_ticket_assignment_notification($ticket, $assigned_agent, $assigner)
{
    $settings = get_settings();

    if (empty($settings['email_notifications_enabled']) || $settings['email_notifications_enabled'] != '1') {
        return; // Notifications disabled
    }

    $lang = $assigned_agent['language'] ?? 'en';
    $template = get_email_template('ticket_assignment', $lang);
    if (!$template) {
        return; // Template not found or not active
    }

    $app_name = !empty($settings['app_name']) ? $settings['app_name'] : 'FoxDesk';
    $ticket_code = get_ticket_code($ticket['id']);
    $ticket_url = get_app_url() . '/index.php?page=ticket&id=' . $ticket['id'];

    // Replace placeholders
    $placeholders = [
        '{ticket_id}' => $ticket['id'],
        '{ticket_code}' => $ticket_code,
        '{ticket_title}' => $ticket['title'],
        '{ticket_url}' => $ticket_url,
        '{agent_name}' => $assigned_agent['first_name'],
        '{agent_full_name}' => $assigned_agent['first_name'] . ' ' . $assigned_agent['last_name'],
        '{assigner_name}' => $assigner['first_name'] . ' ' . $assigner['last_name'],
        '{app_name}' => $app_name
    ];

    $subject = str_replace(array_keys($placeholders), array_values($placeholders), $template['subject']);
    $body = str_replace(array_keys($placeholders), array_values($placeholders), $template['body']);

    send_email($assigned_agent['email'], $subject, $body);
}

/**
 * Send due date reminder notification
 */
function send_due_date_reminder($ticket, $is_overdue = false)
{
    $settings = get_settings();

    if (empty($settings['email_notifications_enabled']) || $settings['email_notifications_enabled'] != '1') {
        return; // Notifications disabled
    }

    // Get assigned user
    if (empty($ticket['assignee_id'])) {
        return; // No one assigned
    }

    $assigned_user = get_user($ticket['assignee_id']);
    if (!$assigned_user || empty($assigned_user['email'])) {
        return;
    }

    $lang = normalize_email_template_language($assigned_user['language'] ?? 'en');
    $ticket_code = get_ticket_code($ticket['id']);
    $ticket_url = get_app_url() . '/index.php?page=ticket&id=' . $ticket['id'];

    $copy = [
        'en' => [
            'subject_overdue' => 'Overdue: {ticket_code} - {title}',
            'subject_due_soon' => 'Due soon: {ticket_code} - {title}',
            'body_overdue' => 'This ticket is now overdue.',
            'body_due_soon' => 'This ticket is due soon.',
            'label_ticket' => 'Ticket',
            'label_due_date' => 'Due date',
            'label_status' => 'Status',
            'label_view_ticket' => 'View ticket'
        ],
        'cs' => [
            'subject_overdue' => 'Po termínu: {ticket_code} - {title}',
            'subject_due_soon' => 'Blíží se termín: {ticket_code} - {title}',
            'body_overdue' => 'Tento požadavek je po termínu.',
            'body_due_soon' => 'Tento požadavek se blíží termínu.',
            'label_ticket' => 'Požadavek',
            'label_due_date' => 'Termín',
            'label_status' => 'Stav',
            'label_view_ticket' => 'Zobrazit požadavek'
        ],
        'de' => [
            'subject_overdue' => 'Überfällig: {ticket_code} - {title}',
            'subject_due_soon' => 'Fällig in Kürze: {ticket_code} - {title}',
            'body_overdue' => 'Dieses Ticket ist jetzt überfällig.',
            'body_due_soon' => 'Dieses Ticket ist bald fällig.',
            'label_ticket' => 'Ticket',
            'label_due_date' => 'Fälligkeitsdatum',
            'label_status' => 'Status',
            'label_view_ticket' => 'Ticket anzeigen'
        ],
        'it' => [
            'subject_overdue' => 'Scaduto: {ticket_code} - {title}',
            'subject_due_soon' => 'In scadenza: {ticket_code} - {title}',
            'body_overdue' => 'Questo ticket è ora scaduto.',
            'body_due_soon' => 'Questo ticket è in scadenza.',
            'label_ticket' => 'Ticket',
            'label_due_date' => 'Scadenza',
            'label_status' => 'Stato',
            'label_view_ticket' => 'Visualizza ticket'
        ],
        'es' => [
            'subject_overdue' => 'Vencido: {ticket_code} - {title}',
            'subject_due_soon' => 'Próximo vencimiento: {ticket_code} - {title}',
            'body_overdue' => 'Este ticket está vencido.',
            'body_due_soon' => 'Este ticket vence pronto.',
            'label_ticket' => 'Ticket',
            'label_due_date' => 'Fecha límite',
            'label_status' => 'Estado',
            'label_view_ticket' => 'Ver ticket'
        ]
    ];
    $i18n = $copy[$lang] ?? $copy['en'];

    if ($is_overdue) {
        $subject = str_replace(
            ['{ticket_code}', '{title}'],
            [$ticket_code, (string) ($ticket['title'] ?? '')],
            $i18n['subject_overdue']
        );
        $body = $i18n['body_overdue'] . "\n\n";
    } else {
        $subject = str_replace(
            ['{ticket_code}', '{title}'],
            [$ticket_code, (string) ($ticket['title'] ?? '')],
            $i18n['subject_due_soon']
        );
        $body = $i18n['body_due_soon'] . "\n\n";
    }

    $body .= $i18n['label_ticket'] . ': ' . ($ticket['title'] ?? '') . "\n";
    $body .= $i18n['label_due_date'] . ': ' . format_date($ticket['due_date']) . "\n";
    $body .= $i18n['label_status'] . ': ' . ($ticket['status_name'] ?? '') . "\n\n";
    $body .= $i18n['label_view_ticket'] . ': ' . $ticket_url . "\n";

    send_email($assigned_user['email'], $subject, $body);
}

/**
 * Send long timer alert notification
 */
function send_long_timer_alert($user, $time_entry, $ticket)
{
    $settings = get_settings();

    if (empty($settings['email_notifications_enabled']) || $settings['email_notifications_enabled'] !== '1') {
        return false;
    }

    $lang = $user['language'] ?? 'en';
    $template = get_email_template('long_timer_alert', $lang);
    $app_name = $settings['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'FoxDesk');
    $ticket_code = get_ticket_code($ticket['id']);
    $ticket_url = get_app_url() . '/index.php?page=ticket&id=' . $ticket['id'];
    $user_name = $user['first_name'] . ' ' . $user['last_name'];

    // Calculate elapsed time
    $started_at = strtotime($time_entry['started_at']);
    $elapsed_seconds = time() - $started_at;
    $hours = floor($elapsed_seconds / 3600);
    $minutes = floor(($elapsed_seconds % 3600) / 60);
    $elapsed_str = $hours . 'h ' . $minutes . 'min';

    if (!$template) {
        $subject = t('Timer running too long on ticket {ticket_code}', ['ticket_code' => $ticket_code]);
        $body = t('Hello {name},', ['name' => $user['first_name']]) . "\n\n";
        $body .= t('Your timer has been running for {elapsed_time} on ticket "{ticket_title}".', [
            'elapsed_time' => $elapsed_str,
            'ticket_title' => $ticket['title']
        ]) . "\n\n";
        $body .= t('Started at') . ': ' . date('Y-m-d H:i', $started_at) . "\n";
        $body .= t('Ticket') . ': ' . $ticket_code . ' - ' . $ticket['title'] . "\n\n";
        $body .= t('Please check if you forgot to stop the timer.') . "\n\n";
        $body .= t('View ticket') . ': ' . $ticket_url . "\n\n";
        $body .= t('Regards') . ",\n" . $app_name;
    } else {
        $subject = $template['subject'];
        $body = $template['body'];
    }

    // Replace placeholders
    $replacements = [
        '{user_name}' => $user_name,
        '{name}' => $user['first_name'],
        '{ticket_id}' => $ticket['id'],
        '{ticket_code}' => $ticket_code,
        '{ticket_title}' => $ticket['title'],
        '{elapsed_time}' => $elapsed_str,
        '{started_at}' => date('Y-m-d H:i', $started_at),
        '{ticket_url}' => $ticket_url,
        '{app_name}' => $app_name
    ];

    foreach ($replacements as $key => $value) {
        $subject = str_replace($key, $value, $subject);
        $body = str_replace($key, $value, $body);
    }

    return send_email($user['email'], $subject, $body, false);
}

