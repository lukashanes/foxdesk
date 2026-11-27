<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/functions.php';
if (!class_exists('DOMDocument')) {
    fwrite(STDERR, "DOM extension required for sanitizer regression\n");
    exit(1);
}
foreach ([
    '<unknown><img src="https://example.test/a.png" onerror="alert(1)"></unknown>',
    '<outer><inner><a href="javascript:alert(1)" onclick="alert(2)">Hello</a><script>alert(3)</script></inner></outer>',
    '<svg><foreignObject><p onmouseover="alert(1)">Hello <b>world</b></p><img src="data:image/svg+xml,x"></foreignObject></svg>',
] as $input) {
    $clean = safe_html($input);
    if (preg_match('/onerror|onclick|onmouseover|javascript:|<script|<svg|data:image/i', $clean)) {
        throw new RuntimeException('Unsafe nested markup survived: ' . $clean);
    }
}
$clean = safe_html('<custom><p>Hello <b>world</b></p><img src="https://example.test/a.png" alt="Photo"></custom>');
if (!str_contains($clean, '<b>world</b>') || !str_contains($clean, 'src="https://example.test/a.png"') || !str_contains($clean, 'alt="Photo"')) {
    throw new RuntimeException('Ordinary nested formatting or image was lost.');
}
echo "Nested HTML sanitizer regression OK\n";

foreach (['<strong onclick=alert(1)>Hello</strong>', '<br onmouseover=alert(1)>', '<a href="jav&#x61;script:alert(1)">Link</a>'] as $input) {
    $fallback = safe_html_regex_fallback($input);
    if (preg_match('/<(?:strong|a|br)[^>]*(?:on\w+\s*=|href\s*=)/i', $fallback)) {
        throw new RuntimeException('The no-DOM fallback retained active HTML.');
    }
}
