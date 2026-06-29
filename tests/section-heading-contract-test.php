<?php

$root = dirname(__DIR__);

function assert_section_heading(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$work = file_get_contents($root . '/pages/work.php');
$settingsTabs = file_get_contents($root . '/includes/components/admin-settings-tabs.php');
$theme = file_get_contents($root . '/theme.css');
$cs = include $root . '/includes/lang/cs.php';
$en = include $root . '/includes/lang/en.php';

assert_section_heading($work !== false, 'Work page must be readable.');
assert_section_heading($settingsTabs !== false, 'Settings tabs component must be readable.');
assert_section_heading($theme !== false, 'theme.css must be readable.');

assert_section_heading(!str_contains($work, 'work-overview-kicker'), 'Work page must not render redundant kicker labels.');
assert_section_heading(!str_contains($settingsTabs, 'settings-management-panel__kicker'), 'Settings management must not render a duplicate kicker label.');

foreach (['fd-section-header', 'fd-section-main', 'fd-section-title', 'fd-section-actions'] as $class) {
    assert_section_heading(str_contains($theme, '.' . $class), 'theme.css missing shared section class: ' . $class);
    assert_section_heading(str_contains($work, $class) || str_contains($settingsTabs, $class), 'shared section class is not used: ' . $class);
}

foreach ([
    "t('Worked time')",
    "t('Now')",
    "t('Activity')</p>",
    "t('Team')</p>",
] as $forbidden) {
    assert_section_heading(!str_contains($work, $forbidden), 'Work page still contains redundant visible copy: ' . $forbidden);
}

assert_section_heading(($cs['Work'] ?? '') === 'Nástěnka', 'Czech Work label must be Nástěnka.');
assert_section_heading(($cs['Work overview'] ?? '') === 'Odpracovaný čas', 'Czech work overview label must be Odpracovaný čas.');
assert_section_heading(($cs['Current work'] ?? '') === 'Právě se řeší', 'Czech current work label must be Právě se řeší.');
assert_section_heading(($cs['My work log'] ?? '') === 'Moje záznamy', 'Czech work log label must be Moje záznamy.');
assert_section_heading(($cs['All work'] ?? '') === 'Vyhledat', 'Czech all-work fallback must read as search.');
assert_section_heading(($en['Work overview'] ?? '') === 'Worked time', 'English work overview label must be concise.');
assert_section_heading(($en['Current work'] ?? '') === 'In progress now', 'English current work label must be concise.');
assert_section_heading(($en['My work log'] ?? '') === 'My entries', 'English work log label must be concise.');

echo "Section heading contract OK\n";
