<?php
/**
 * Shared email rendering helpers.
 *
 * Existing templates are still supported. New modules should render both HTML
 * and text through this layer instead of composing ad-hoc email bodies.
 */

function foxdesk_email_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function foxdesk_email_normalize_subject($subject, string $fallback = 'FoxDesk update'): string
{
    $subject = preg_replace('/[\r\n\t]+/', ' ', (string) $subject);
    $subject = preg_replace('/\s{2,}/', ' ', (string) $subject);
    $subject = trim((string) $subject);
    if ($subject === '') {
        $subject = $fallback;
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($subject) > 150 ? rtrim(mb_substr($subject, 0, 147)) . '...' : $subject;
    }

    return strlen($subject) > 150 ? rtrim(substr($subject, 0, 147)) . '...' : $subject;
}

function foxdesk_ticket_email_subject(string $event, array $ticket = [], array $context = []): string
{
    $event = function_exists('ticket_event_normalize') ? ticket_event_normalize($event) : strtolower(trim($event));
    $labels = [
        'ticket.created' => 'New ticket',
        'ticket.created.confirmation' => 'Ticket received',
        'ticket.customer_replied' => 'Customer replied',
        'ticket.agent_replied' => 'Reply added',
        'ticket.assigned' => 'Assigned to you',
        'ticket.waiting_for_customer' => 'Waiting for customer',
        'ticket.waiting_for_agent' => 'Waiting for team',
        'ticket.completed' => 'Completed',
        'ticket.status_changed' => 'Status updated',
        'ticket.due_soon' => 'Due soon',
        'ticket.overdue' => 'Overdue',
        'ticket.mentioned' => 'Mentioned',
    ];

    $label = $labels[$event] ?? 'Ticket update';
    $code = trim((string) ($context['ticket_code'] ?? $ticket['ticket_code'] ?? ''));
    $title = trim((string) ($ticket['title'] ?? $context['ticket_title'] ?? ''));

    $subject = $label;
    if ($code !== '') {
        $subject .= ' ' . $code;
    } elseif (!empty($ticket['id'])) {
        $subject .= ' #' . (int) $ticket['id'];
    }
    if ($title !== '') {
        $subject .= ': ' . $title;
    }

    return foxdesk_email_normalize_subject($subject, $label);
}

function foxdesk_render_email_body_html($body): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim((string) $body));
    if ($text === '') {
        return '';
    }

    $blocks = preg_split("/\n{2,}/", $text) ?: [];
    $html = [];
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        if (preg_match('/^-{3,}$/', $block)) {
            $html[] = '<hr style="border:0;border-top:1px solid #e2e8f0;margin:18px 0">';
            continue;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn($line) => $line !== ''));
        $all_bullets = !empty($lines);
        foreach ($lines as $line) {
            if (!preg_match('/^[-*]\s+/', $line)) {
                $all_bullets = false;
                break;
            }
        }

        if ($all_bullets) {
            $items = '';
            foreach ($lines as $line) {
                $items .= '<li style="margin:0 0 6px">' . foxdesk_email_escape(preg_replace('/^[-*]\s+/', '', $line)) . '</li>';
            }
            $html[] = '<ul style="margin:0 0 14px 20px;padding:0;color:#334155;font-size:15px;line-height:23px">' . $items . '</ul>';
            continue;
        }

        $html[] = '<p style="margin:0 0 14px;color:#334155;font-size:15px;line-height:23px;white-space:pre-line">' . foxdesk_email_escape($block) . '</p>';
    }

    return implode('', $html);
}

function foxdesk_render_ticket_email_html(array $payload): string
{
    $app_name = foxdesk_email_escape($payload['app_name'] ?? 'FoxDesk');
    $eyebrow = foxdesk_email_escape($payload['eyebrow'] ?? '');
    $title = foxdesk_email_escape(foxdesk_email_normalize_subject($payload['title'] ?? '', 'Ticket update'));
    $preheader = foxdesk_email_escape($payload['preheader'] ?? $payload['eyebrow'] ?? 'Open FoxDesk to review the update.');
    $body = foxdesk_render_email_body_html($payload['body'] ?? '');
    $cta_label = foxdesk_email_escape($payload['cta_label'] ?? 'Open ticket');
    $cta_url = foxdesk_email_escape($payload['cta_url'] ?? '');
    $reason = foxdesk_email_escape($payload['reason'] ?? 'You are receiving this because you are connected to this ticket.');

    $cta = $cta_url !== ''
        ? '<p style="margin:24px 0 0"><a href="' . $cta_url . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;border-radius: var(--fd-radius-control);padding:11px 16px;font-weight:700">' . $cta_label . '</a></p>'
        : '';
    $reason_html = $reason !== ''
        ? '<p style="margin:22px 0 0;color:#64748b;font-size:12px;line-height:18px">' . $reason . '</p>'
        : '';

    return '<!doctype html><html><body style="margin:0;background:#f8fafc;font-family:Inter,Arial,sans-serif;color:#0f172a">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . $preheader . '</div>'
        . '<div style="max-width:620px;margin:0 auto;padding:32px 18px">'
        . '<div style="font-weight:800;font-size:18px;margin-bottom:18px">' . $app_name . '</div>'
        . '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.06)">'
        . ($eyebrow !== '' ? '<div style="color:#64748b;font-size:13px;font-weight:700;margin-bottom:8px">' . $eyebrow . '</div>' : '')
        . '<h1 style="margin:0 0 14px;font-size:24px;line-height:30px">' . $title . '</h1>'
        . '<div>' . $body . '</div>'
        . $cta
        . $reason_html
        . '</div><p style="margin:16px 0 0;color:#94a3b8;font-size:12px;line-height:18px">FoxDesk keeps ticket emails short. Reply in the app when you need the full history.</p></div></body></html>';
}

function foxdesk_render_ticket_email_text(array $payload): string
{
    $parts = [];
    foreach (['app_name', 'eyebrow', 'title', 'body'] as $key) {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    $cta_url = trim((string) ($payload['cta_url'] ?? ''));
    if ($cta_url !== '') {
        $cta_label = trim((string) ($payload['cta_label'] ?? 'Open ticket'));
        $parts[] = ($cta_label !== '' ? $cta_label : 'Open ticket') . ': ' . $cta_url;
    }
    $reason = trim((string) ($payload['reason'] ?? ''));
    if ($reason !== '') {
        $parts[] = $reason;
    }
    return trim(implode("\n\n", $parts));
}
