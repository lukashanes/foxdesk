<?php
/**
 * Settings view model helpers.
 */

function settings_normalize_tab(string $tab): string
{
    if (in_array($tab, ['statuses', 'priorities', 'ticket-types'], true)) {
        return 'workflow';
    }
    return $tab;
}

function settings_tab_from_request(array $request): string
{
    return settings_normalize_tab((string) ($request['tab'] ?? 'general'));
}
