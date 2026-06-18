<?php
/**
 * Settings email action metadata.
 */

function settings_email_action_keys(): array
{
    return ['save_email', 'test_smtp', 'test_imap', 'run_imap_now', 'save_template'];
}

function settings_is_email_action(array $post): bool
{
    foreach (settings_email_action_keys() as $key) {
        if (isset($post[$key])) {
            return true;
        }
    }
    return false;
}
