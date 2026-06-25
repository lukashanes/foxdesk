<?php

$root = dirname(__DIR__);
$page = file_get_contents($root . '/pages/ticket-detail.php');
$asset = file_get_contents($root . '/assets/js/ticket-detail.js');
$paste_drop_asset = file_get_contents($root . '/assets/js/attachment-paste-drop.js');
$theme = file_get_contents($root . '/theme.css');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($page !== false, 'Ticket detail page must be readable.');
$assert($asset !== false, 'Ticket detail JS asset must be readable.');
$assert($paste_drop_asset !== false, 'Attachment paste/drop JS asset must be readable.');
$assert($theme !== false, 'Theme CSS must be readable.');

foreach ([
    'window.FoxDeskTicketDetailConfig',
    'assets/js/ticket-detail.js',
    'assets/js/quill-image-upload.js',
    'assets/js/attachment-paste-drop.js',
    'assets/js/autosave.js',
] as $needle) {
    $assert(str_contains($page, $needle), 'Ticket detail page missing JS contract: ' . $needle);
}

foreach ([
    'function quickEditField',
    'function openEditCommentModal',
    'function openEditTimeEntry',
    'function openEditTicketModal',
    'function openTicketTimeline',
    'const ICONS',
    'let commentEditor',
    'let editDescriptionEditor',
] as $inlineNeedle) {
    $assert(!str_contains($page, $inlineNeedle), 'Ticket detail page must not own inline JS behavior: ' . $inlineNeedle);
}

foreach ([
    'window.quickEditField',
    'window.openEditCommentModal',
    'window.openEditTimeEntry',
    'window.openEditTicketModal',
    'window.openTicketTimeline',
    'initUploadPreview',
    'FoxDeskAttachmentPasteDrop.bind',
    "targetSelectors: ['#comment-form', '#comment-upload-zone']",
    'initQuillEditors',
    'initTags',
    'initTimer',
    'updateCompleteActionTitle',
    'completeTimerHelp',
    'completeHelp',
    'initAutosave',
    "classList.add('is-open')",
    "classList.remove('is-open')",
    "classList.add('ticket-timeline-open')",
    "classList.remove('ticket-timeline-open')",
    'ticket-timeline-empty',
] as $assetNeedle) {
    $assert(str_contains($asset, $assetNeedle), 'Ticket detail JS asset missing behavior: ' . $assetNeedle);
}

foreach ([
    'window.FoxDeskAttachmentPasteDrop',
    'function autoBindKnownSurfaces',
    "inputId: 'comment-file-input'",
    "inputId: 'file-input'",
    "targetSelectors: ['#comment-form', '#comment-upload-zone']",
    "targetSelectors: ['#new-ticket-form', '#upload-zone']",
] as $pasteDropNeedle) {
    $assert(str_contains($paste_drop_asset, $pasteDropNeedle), 'Attachment paste/drop asset missing behavior: ' . $pasteDropNeedle);
}

foreach ([
    'body.ticket-timeline-open',
    '.ticket-timeline-overlay.is-open',
    '.ticket-timeline-empty',
] as $themeNeedle) {
    $assert(str_contains($theme, $themeNeedle), 'Theme CSS missing ticket timeline state: ' . $themeNeedle);
}

echo "Ticket detail JS contract OK\n";
