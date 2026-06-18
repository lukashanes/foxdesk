<?php
/**
 * Settings security/log action metadata.
 */

function settings_security_action_keys(): array
{
    return ['save_2fa_settings', 'clear_logs', 'clear_security_logs'];
}

function settings_is_security_action(array $post): bool
{
    foreach (settings_security_action_keys() as $key) {
        if (isset($post[$key])) {
            return true;
        }
    }
    return false;
}
