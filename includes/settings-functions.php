<?php
/**
 * Settings Management Functions
 *
 * This file contains functions for managing application settings,
 * including retrieving and saving configuration values.
 */

/**
 * Get all settings as array
 */
function get_settings() {
    global $cached_settings;

    if (!is_array($cached_settings)) {
        $cached_settings = [];
        try {
            $rows = db_fetch_all("SELECT setting_key, setting_value FROM settings");
            foreach ($rows as $row) {
                $cached_settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }
    }

    return $cached_settings;
}

/**
 * Get single setting
 */
function get_setting($key, $default = null) {
    $settings = get_settings();
    return $settings[$key] ?? $default;
}

/**
 * Save setting
 */
function save_setting($key, $value) {
    global $cached_settings;
    $cached_settings = null; // Clear cache

    $existing = db_fetch_one("SELECT id FROM settings WHERE setting_key = ?", [$key]);

    if ($existing) {
        db_update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
    } else {
        db_insert('settings', [
            'setting_key' => $key,
            'setting_value' => $value
        ]);
    }
}

