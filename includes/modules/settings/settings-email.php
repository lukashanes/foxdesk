<?php
/**
 * Settings email action metadata.
 */

function settings_email_surface_type(): string
{
    return 'self_hosted';
}

function settings_email_is_managed_surface(): bool
{
    return false;
}

function settings_email_action_keys(?string $surface = null): array
{
    return ['save_email', 'test_smtp', 'test_imap', 'run_imap_now', 'save_template'];
}

function settings_is_email_action(array $post, ?string $surface = null): bool
{
    foreach (settings_email_action_keys() as $key) {
        if (isset($post[$key])) {
            return true;
        }
    }
    return false;
}
