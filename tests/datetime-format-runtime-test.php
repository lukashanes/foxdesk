<?php
define('BASE_PATH', dirname(__DIR__));
$clockFormat = '24';
function get_setting($key, $default = null) { return $key === 'time_format' ? $GLOBALS['clockFormat'] : $default; }
require BASE_PATH . '/includes/locale-functions.php';
$failures = [];
if (!class_exists('IntlDateFormatter')) {
    foreach (['UTC', 'Europe/Prague'] as $timezone) {
        date_default_timezone_set($timezone);
        foreach (['24', '12'] as $clockFormat) {
            foreach (array_keys(foxdesk_locale_registry()) as $locale) {
                foreach ([0, 1729992600] as $timestamp) {
                    foreach ([false, true] as $withTime) {
                        $pattern = foxdesk_fallback_date_pattern($locale, false);
                        if ($withTime) $pattern .= $clockFormat === '12' ? ' g:i A' : ' H:i';
                        if (foxdesk_format_datetime($timestamp, $withTime, $locale) !== date($pattern, $timestamp)) {
                            $failures[] = "$locale/$timezone/$clockFormat/$timestamp";
                        }
                    }
                }
            }
        }
    }
    if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
    echo "Datetime fallback without Intl passed\n";
    exit(0);
}
foreach (array_keys(foxdesk_locale_registry()) as $locale) {
    foreach (['UTC', 'Europe/Prague', 'America/New_York'] as $timezone) {
        date_default_timezone_set($timezone);
        foreach (['24', '12'] as $clockFormat) {
            foreach ([0, 1711845000, 1729992600] as $timestamp) {
                $date = new IntlDateFormatter($locale, IntlDateFormatter::SHORT, IntlDateFormatter::NONE, $timezone);
                $time = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $timezone);
                $time->setPattern($clockFormat === '12' ? 'h:mm a' : 'HH:mm');
                foreach ([false, true] as $withTime) {
                    $expected = $date->format($timestamp) . ($withTime ? ' ' . $time->format($timestamp) : '');
                    if (foxdesk_format_datetime($timestamp, $withTime, $locale) !== $expected) $failures[] = "$locale/$timezone/$clockFormat/$timestamp";
                }
            }
        }
    }
}
if (foxdesk_format_datetime('invalid') !== '' || foxdesk_format_datetime(null) !== '') $failures[] = 'Invalid dates must remain empty.';
date_default_timezone_set('Europe/Prague'); $clockFormat = '24';
$runs = [];
for ($pass = 0; $pass < 5; $pass++) {
    $start = hrtime(true);
    for ($i = 0; $i < 1000; $i++) foxdesk_format_datetime(1729992600 + $i, true, 'cs');
    $runs[] = (hrtime(true) - $start) / 1e6;
}
sort($runs); printf("datetime 1000 rows median_ms=%.3f\n", $runs[2]);
if ($failures) { fwrite(STDERR, count($failures) . ' datetime mismatches; first: ' . $failures[0] . "\n"); exit(1); }
echo "Datetime locale, timezone, DST and epoch parity passed\n";
