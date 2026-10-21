<?php
/**
 * Rendering stored rich text (web editor or inbound email HTML) must keep
 * tables and remote https images intact, strip unsafe attributes, and only
 * linkify URLs in text nodes, never inside tag attributes.
 */
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/functions.php';

function assert_rich_render(bool $condition, string $message, string $actual = ''): void
{
    if (!$condition) {
        fwrite(STDERR, 'Rich content render test failed: ' . $message . PHP_EOL . $actual . PHP_EOL);
        exit(1);
    }
}

$html = '<p>Hello</p><img src="https://example.com/a.png" onerror="alert(1)" style="x"><table border="1"><tr><th>Item</th><td onclick="x()">Printer</td></tr></table><hr><p>See https://example.com/docs today.</p>';
$rendered = render_content($html);
assert_rich_render(str_contains($rendered, '<img src="https://example.com/a.png" alt="" loading="lazy" class="rich-inline-image">'), 'Remote https images must keep a valid src attribute.', $rendered);
assert_rich_render(str_contains($rendered, '<table><tr><th>Item</th><td>Printer</td></tr></table>'), 'Tables must render without attributes.', $rendered);
assert_rich_render(str_contains($rendered, '<hr>'), 'Horizontal rules must render.', $rendered);
assert_rich_render(!str_contains($rendered, 'onerror') && !str_contains($rendered, 'onclick') && !str_contains($rendered, 'style='), 'Unsafe attributes must be removed.', $rendered);
assert_rich_render(str_contains($rendered, '<a href="https://example.com/docs" target="_blank" rel="noopener noreferrer">https://example.com/docs</a>'), 'URLs in text must still be linkified.', $rendered);

$plain = render_content("Plain https://example.com/x text");
assert_rich_render(str_contains($plain, '<a href="https://example.com/x"'), 'Plain-text URLs must be linkified.', $plain);

echo "Rich content render tests passed\n";
