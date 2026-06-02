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

function foxdesk_render_ticket_email_html(array $payload): string
{
    $app_name = foxdesk_email_escape($payload['app_name'] ?? 'FoxDesk');
    $eyebrow = foxdesk_email_escape($payload['eyebrow'] ?? '');
    $title = foxdesk_email_escape($payload['title'] ?? '');
    $body = nl2br(foxdesk_email_escape($payload['body'] ?? ''));
    $cta_label = foxdesk_email_escape($payload['cta_label'] ?? 'Open ticket');
    $cta_url = foxdesk_email_escape($payload['cta_url'] ?? '');
    $reason = foxdesk_email_escape($payload['reason'] ?? '');

    $cta = $cta_url !== ''
        ? '<p style="margin:24px 0 0"><a href="' . $cta_url . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;border-radius:10px;padding:11px 16px;font-weight:700"> ' . $cta_label . ' </a></p>'
        : '';
    $reason_html = $reason !== ''
        ? '<p style="margin:22px 0 0;color:#64748b;font-size:12px;line-height:18px">' . $reason . '</p>'
        : '';

    return '<!doctype html><html><body style="margin:0;background:#f8fafc;font-family:Inter,Arial,sans-serif;color:#0f172a">'
        . '<div style="max-width:620px;margin:0 auto;padding:32px 18px">'
        . '<div style="font-weight:800;font-size:18px;margin-bottom:18px">' . $app_name . '</div>'
        . '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.06)">'
        . ($eyebrow !== '' ? '<div style="color:#64748b;font-size:13px;font-weight:700;margin-bottom:8px">' . $eyebrow . '</div>' : '')
        . '<h1 style="margin:0 0 14px;font-size:24px;line-height:30px">' . $title . '</h1>'
        . '<div style="font-size:15px;line-height:23px;color:#334155">' . $body . '</div>'
        . $cta
        . $reason_html
        . '</div></div></body></html>';
}

function foxdesk_render_ticket_email_text(array $payload): string
{
    $parts = [];
    foreach (['app_name', 'eyebrow', 'title', 'body', 'cta_url', 'reason'] as $key) {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    return trim(implode("\n\n", $parts));
}
