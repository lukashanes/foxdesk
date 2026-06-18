<?php
/**
 * Settings email-template view models.
 */

function settings_email_template_catalog(): array
{
    return [
        'status_change' => [
            'name' => t('Status change'),
            'description' => t('Sent to requester when ticket status changes.'),
            'variables' => [
                '{ticket_id}' => t('Ticket ID'),
                '{ticket_title}' => t('Ticket subject'),
                '{old_status}' => t('Previous status'),
                '{new_status}' => t('New status'),
                '{comment_text}' => t('Comment text (if any)'),
                '{time_spent}' => t('Time spent'),
                '{ticket_url}' => t('Ticket URL'),
                '{app_name}' => t('App name'),
            ],
        ],
        'new_comment' => [
            'name' => t('New comment'),
            'description' => t('Sent to requester when a new comment is added.'),
            'variables' => [
                '{ticket_id}' => t('Ticket ID'),
                '{ticket_title}' => t('Ticket subject'),
                '{comment_text}' => t('Comment text'),
                '{commenter_name}' => t('Comment author name'),
                '{time_spent}' => t('Time spent'),
                '{attachments}' => t('Attachment list'),
                '{ticket_url}' => t('Ticket URL'),
                '{comment_url}' => t('Comment URL'),
                '{app_name}' => t('App name'),
            ],
        ],
        'new_ticket' => [
            'name' => t('New ticket'),
            'description' => t('Sent to admins when a new ticket is created.'),
            'variables' => [
                '{ticket_id}' => t('Ticket ID'),
                '{ticket_title}' => t('Ticket subject'),
                '{ticket_type}' => t('Ticket type'),
                '{priority}' => t('Priority'),
                '{user_name}' => t('Requester name'),
                '{user_email}' => t('Requester email'),
                '{description}' => t('Ticket description'),
                '{ticket_url}' => t('Ticket URL'),
                '{app_name}' => t('App name'),
            ],
        ],
        'password_reset' => [
            'name' => t('Password reset'),
            'description' => t('Sent when a password reset is requested.'),
            'variables' => [
                '{name}' => t('User name'),
                '{reset_link}' => t('Reset link'),
                '{app_name}' => t('App name'),
            ],
        ],
        'ticket_confirmation' => [
            'name' => t('Ticket received'),
            'description' => t('Sent to requester after a new ticket is created.'),
            'variables' => [
                '{ticket_id}' => t('Ticket ID'),
                '{ticket_code}' => t('Ticket code (e.g., TK-0003)'),
                '{ticket_title}' => t('Ticket subject'),
                '{ticket_url}' => t('Ticket URL'),
                '{app_name}' => t('App name'),
            ],
        ],
        'ticket_assignment' => [
            'name' => t('Ticket assignment'),
            'description' => t('Sent to agents when a ticket is assigned.'),
            'variables' => [
                '{ticket_id}' => t('Ticket ID'),
                '{ticket_code}' => t('Ticket code (e.g., TK-0003)'),
                '{ticket_title}' => t('Ticket subject'),
                '{agent_name}' => t('Agent first name'),
                '{agent_full_name}' => t('Agent full name'),
                '{assigner_name}' => t('Assigner name'),
                '{ticket_url}' => t('Ticket URL'),
                '{app_name}' => t('App name'),
            ],
        ],
        'recurring_task_assignment' => [
            'name' => t('Recurring task assignment'),
            'description' => t('Sent when a recurring task creates a new ticket assigned to a user.'),
            'variables' => [
                '{ticket_id}' => t('Ticket ID'),
                '{ticket_code}' => t('Ticket code'),
                '{ticket_title}' => t('Task title'),
                '{ticket_description}' => t('Task description'),
                '{due_date}' => t('Due date'),
                '{ticket_url}' => t('Ticket URL'),
                '{recipient_name}' => t('Recipient name'),
                '{app_name}' => t('App name'),
            ],
        ],
        'long_timer_alert' => [
            'name' => t('Long timer alert'),
            'description' => t('Sent when a user\'s timer has been running for too long.'),
            'variables' => [
                '{user_name}' => t('User name'),
                '{ticket_id}' => t('Ticket ID'),
                '{ticket_code}' => t('Ticket code'),
                '{ticket_title}' => t('Ticket title'),
                '{elapsed_time}' => t('Elapsed time'),
                '{started_at}' => t('Timer start time'),
                '{ticket_url}' => t('Ticket URL'),
                '{app_name}' => t('App name'),
            ],
        ],
        'welcome_email' => [
            'name' => t('Welcome email'),
            'description' => t('Sent to new users with their login credentials when "Send login credentials via email" is checked.'),
            'variables' => [
                '{name}' => t('User first name'),
                '{email}' => t('User email'),
                '{password}' => t('User password'),
                '{login_url}' => t('Login URL'),
                '{app_name}' => t('App name'),
            ],
        ],
    ];
}

function settings_email_template_defaults(): array
{
    if (function_exists('get_builtin_email_templates')) {
        return get_builtin_email_templates();
    }

    $defaults = [];
    foreach (array_keys(settings_email_template_catalog()) as $key) {
        foreach (['en', 'cs', 'de', 'it', 'es'] as $lang) {
            if (!function_exists('get_builtin_email_template')) {
                continue;
            }
            $template = get_builtin_email_template($key, $lang);
            if (is_array($template)) {
                $defaults[$key][$lang] = [
                    'subject' => (string) ($template['subject'] ?? ''),
                    'body' => (string) ($template['body'] ?? ''),
                ];
            }
        }
    }
    return $defaults;
}

function settings_email_template_english_rows(): array
{
    try {
        $rows = db_fetch_all("
            SELECT template_key, subject, body
            FROM email_templates
            WHERE language = 'en'
        ");
    } catch (Throwable $e) {
        $rows = [];
    }

    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row['template_key']] = $row;
    }
    return $map;
}

function settings_email_template_display_rows(array $templates, string $language): array
{
    $catalog = settings_email_template_catalog();
    $defaults = settings_email_template_defaults();
    $english_template_map = settings_email_template_english_rows();

    $template_map = [];
    foreach ($templates as $template) {
        $template_map[(string) $template['template_key']] = $template;
    }

    $display_templates = [];
    foreach ($catalog as $key => $_info) {
        if (isset($template_map[$key])) {
            $display_templates[] = $template_map[$key];
            continue;
        }

        $default_subject = '';
        $default_body = '';
        if (isset($defaults[$key][$language])) {
            $default_subject = (string) ($defaults[$key][$language]['subject'] ?? '');
            $default_body = (string) ($defaults[$key][$language]['body'] ?? '');
        } elseif (isset($english_template_map[$key])) {
            $default_subject = (string) ($english_template_map[$key]['subject'] ?? '');
            $default_body = (string) ($english_template_map[$key]['body'] ?? '');
        } elseif (isset($defaults[$key]['en'])) {
            $default_subject = (string) ($defaults[$key]['en']['subject'] ?? '');
            $default_body = (string) ($defaults[$key]['en']['body'] ?? '');
        }

        $display_templates[] = [
            'template_key' => $key,
            'subject' => $default_subject,
            'body' => $default_body,
            'language' => $language,
        ];
    }
    return $display_templates;
}
