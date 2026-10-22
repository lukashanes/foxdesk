<?php

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/locale-functions.php';
require_once BASE_PATH . '/includes/ticket-query-functions.php';

function assert_cjk_contract($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

foreach (['客户', '顧客', 'カスタマー', 'ひらがな', '고객'] as $query) {
    assert_cjk_contract(foxdesk_contains_cjk($query), "CJK query was not detected: {$query}");
}
assert_cjk_contract(!foxdesk_contains_cjk('customer 123 😀'), 'Latin and emoji-only queries must keep the normal search path.');

$cjk = build_ticket_search_clause('顧客データ', true, true);
assert_cjk_contract($cjk['mode'] === 'like', 'CJK must bypass FULLTEXT even when the index exists.');
assert_cjk_contract(strpos($cjk['sql'], 'MATCH(') === false, 'CJK fallback must not include MATCH AGAINST.');
assert_cjk_contract(substr_count($cjk['sql'], "LIKE ? ESCAPE '='") === 3, 'CJK fallback must search title, description and tags.');
assert_cjk_contract($cjk['params'] === ['%顧客データ%', '%顧客データ%', '%顧客データ%'], 'CJK query parameters are not stable.');

$latin = build_ticket_search_clause('billing issue', true, true);
assert_cjk_contract($latin['mode'] === 'fulltext', 'Whitespace-delimited language should keep FULLTEXT.');
assert_cjk_contract(strpos($latin['sql'], 'MATCH(t.title, t.description)') !== false, 'Latin FULLTEXT clause is missing.');

$literal = build_ticket_search_clause('50%_done', false, false);
assert_cjk_contract(
    $literal['params'] === ['%50=%=_done%', '%50=%=_done%'],
    'LIKE wildcards supplied by a user must be treated literally.'
);

$numeric = build_ticket_search_clause('123', false, true);
assert_cjk_contract(strpos($numeric['sql'], 't.id = ?') !== false, 'Numeric ticket ID lookup must remain available.');
assert_cjk_contract(end($numeric['params']) === 123, 'Numeric ticket ID must be bound as an integer.');

$imeFiles = [
    'assets/js/shortcuts.js',
    'assets/js/ticket-list.js',
    'assets/js/ticket-detail.js',
    'assets/js/autosave.js',
    'assets/js/chip-select.js',
];
foreach ($imeFiles as $file) {
    $source = (string) file_get_contents(BASE_PATH . '/' . $file);
    assert_cjk_contract(strpos($source, 'compositionstart') !== false, "{$file} does not pause during IME composition.");
    assert_cjk_contract(strpos($source, 'compositionend') !== false, "{$file} does not resume after IME composition.");
}
assert_cjk_contract(
    strpos((string) file_get_contents(BASE_PATH . '/assets/js/autosave.js'), '_compositionDepth === 0') !== false,
    'Autosave may persist an unfinished IME composition.'
);

$bidiSurfaces = [
    'includes/components/ticket-detail-content.php',
    'includes/components/ticket-list-table.php',
    'includes/components/ticket-list-board.php',
    'pages/ticket-share.php',
    'pages/report-public.php',
];
foreach ($bidiSurfaces as $file) {
    $source = (string) file_get_contents(BASE_PATH . '/' . $file);
    assert_cjk_contract(strpos($source, 'dir="auto"') !== false, "{$file} lacks automatic direction for user content.");
}

echo "CJK search, IME and bidi surface tests passed\n";
