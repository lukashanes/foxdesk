<?php

$root = dirname(__DIR__);
$header = file_get_contents($root . '/includes/header.php');
$theme = file_get_contents($root . '/theme.css');
$footer = file_get_contents($root . '/includes/footer.php');
$appHeaderJs = file_get_contents($root . '/assets/js/app-header.js');
$pageTransitionsJs = file_get_contents($root . '/assets/js/page-transitions.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($header !== false && $theme !== false && $footer !== false && $appHeaderJs !== false && $pageTransitionsJs !== false, 'App shell files must be readable.');

$assert(str_contains($header, 'class="app-shell-page antialiased font-sans"'), 'Self-hosted pages must opt into app shell styling.');
$assert(str_contains($header, 'data-app-shell="self-hosted"'), 'Self-hosted shell must identify its edition.');
$assert(str_contains($header, 'class="app-topbar desktop-header'), 'Desktop header must use app-topbar shell class.');
$assert(str_contains($header, 'class="app-topbar mobile-header'), 'Mobile header must use app-topbar shell class.');
$assert(str_contains($header, 'app-shell-context'), 'Workspace header must show a compact workspace context.');
$assert(str_contains($header, 'class="app-content"'), 'Page content must be wrapped in app-content.');
$assert(str_contains($header, 'class="header-search-form relative"'), 'Desktop header search must use the shared search form class.');
$assert(str_contains($header, 'class="form-input pr-4 header-search-input"'), 'Header search input must use shared input spacing class.');
$assert(str_contains($header, 'class="header-search-icon absolute top-1/2 transform -translate-y-1/2"'), 'Header search icon must use shared absolute-position class.');

$headerInlineStyleAttrs = preg_match_all('/\sstyle\s*=\s*["\']/', $header);
$assert($headerInlineStyleAttrs === 0, 'Workspace header shell must not reintroduce inline style attributes.');
$assert(!str_contains($header, '<style>'), 'Workspace header shell must keep visual CSS in theme.css.');
$assert(!str_contains($header, "style=\"background:"), 'Notification avatars must use tokenized classes, not inline colors.');

foreach ([
    '--app-sidebar-width: 280px;',
    '--app-sidebar-compact-width: 76px;',
    '--app-content-max: 1480px;',
    '.app-shell-page .app-content',
    '.app-topbar',
    '.app-shell-context',
    '.sidebar-timers',
    '.sidebar-icon-action',
    '.notification-panel',
    '.notification-toggle-btn',
    '.notification-link-button',
    '.notif-avatar--0',
    '@keyframes fd-page-enter',
    '.app-shell-page.is-page-leaving .app-content',
    '@media (prefers-reduced-motion: reduce)',
] as $needle) {
    $assert(str_contains($theme, $needle), 'theme.css missing app shell visual contract: ' . $needle);
}

$assert(str_contains($footer, 'assets/js/page-transitions.js'), 'Footer must load smooth page transitions.');
$assert(str_contains($pageTransitionsJs, 'is-page-leaving'), 'Page transition JS must set the leaving state.');
$assert(str_contains($pageTransitionsJs, 'prefers-reduced-motion: reduce'), 'Page transition JS must respect reduced motion.');
$assert(str_contains($pageTransitionsJs, "closest('a[href]')"), 'Page transition JS must enhance ordinary internal links.');

$assert(!str_contains($header, 'width: clamp(200px, 25vw, 320px)'), 'Header search width must be controlled by app shell CSS, not inline clamp.');
$assert(!str_contains($header, "compact ? '76px'"), 'Header inline sidebar sync must read compact width from CSS tokens.');
$assert(str_contains($appHeaderJs, '--app-sidebar-compact-width'), 'Sidebar JS must read compact width from CSS tokens.');
$assert(!str_contains($header, "url('platform'"), 'Self-hosted shell must not expose SaaS platform navigation.');

echo "Self-hosted app shell visual contract OK\n";
