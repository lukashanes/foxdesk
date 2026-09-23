<?php

$root = dirname(__DIR__);
require_once __DIR__ . '/support/ticket-detail-source.php';
$page = file_get_contents($root . '/pages/ticket-detail.php');
$time_row = file_get_contents($root . '/includes/components/ticket-workspace-time-row.php');
$asset = ticket_detail_browser_source($root);
$records_asset = file_get_contents($root . '/assets/js/ticket-detail-records.js');
$rich_text_asset = file_get_contents($root . '/assets/js/rich-text-editor.js');
$paste_drop_asset = file_get_contents($root . '/assets/js/attachment-paste-drop.js');
$quill_upload_asset = file_get_contents($root . '/assets/js/quill-image-upload.js');
$upload_preview_asset = file_get_contents($root . '/assets/js/upload-preview.js');
$image_preview_asset = file_get_contents($root . '/assets/js/image-preview.js');
$attachment_grid = file_get_contents($root . '/includes/components/attachment-grid.php');
$footer = file_get_contents($root . '/includes/footer.php');
$app_footer_asset = file_get_contents($root . '/assets/js/app-footer.js');
$upload_handler = file_get_contents($root . '/includes/api/upload-handler.php');
$theme = file_get_contents($root . '/theme.css');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($page !== false, 'Ticket detail page must be readable.');
$assert($asset !== false, 'Ticket detail JS asset must be readable.');
$assert($records_asset !== false, 'Ticket detail records JS asset must be readable.');
$assert($rich_text_asset !== false, 'Rich text editor JS asset must be readable.');
$assert($paste_drop_asset !== false, 'Attachment paste/drop JS asset must be readable.');
$assert($quill_upload_asset !== false, 'Quill image upload JS asset must be readable.');
$assert($upload_preview_asset !== false, 'Upload preview JS asset must be readable.');
$assert($image_preview_asset !== false, 'Image preview JS asset must be readable.');
$assert($attachment_grid !== false, 'Attachment grid component must be readable.');
$assert($footer !== false, 'Footer must be readable.');
$assert($app_footer_asset !== false, 'App footer JS asset must be readable.');
$assert($upload_handler !== false, 'Upload handler must be readable.');
$assert($theme !== false, 'Theme CSS must be readable.');

foreach ([
    'window.FoxDeskTicketDetailConfig',
    'assets/js/ticket-detail.js',
    'assets/js/rich-text-editor.js',
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
    'window.deleteAttachment',
    'window.deleteComment',
    'window.deleteTimeEntry',
    'restoreDeletedItem',
    'showUndoToast',
    'index.php?page=api&action=delete-attachment',
    "index.php?page=api&action=delete-comment",
    "index.php?page=api&action=delete-time-entry",
    "index.php?page=api&action=' + encodeURIComponent(action)",
    'initUploadPreview',
    'FoxDeskAttachmentPasteDrop.bind',
    "targetSelectors: ['#comment-form', '#comment-upload-zone']",
    'initQuillEditors',
    'initTicketEditModalControls',
    "document.querySelectorAll('[data-ticket-edit-open]')",
    "document.querySelectorAll('[data-ticket-edit-close]')",
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
    'quillFieldValue(window.commentEditor)',
    'quillFieldValue(window.internalEditor)',
    'quillFieldValue(editDescriptionEditor)',
    'quillFieldValue(editCommentEditor)',
    'loadQuillContent(editCommentEditor, content)',
] as $assetNeedle) {
    $assert(str_contains($asset, $assetNeedle), 'Ticket detail JS asset missing behavior: ' . $assetNeedle);
}

$assert(str_contains($asset, 'quillToolbar: quillToolbar'), 'Ticket detail runtime must expose the shared Quill toolbar.');
$assert(str_contains($records_asset, 'var quillToolbar = runtime.quillToolbar;'), 'Comment editing must use the Quill toolbar from the shared runtime.');

$assert(!str_contains($asset, "confirmDeleteComment"), 'Comment deletion must not show a verification dialog.');
$assert(!str_contains($page, "Delete this time entry?"), 'Time entry deletion must not show a verification dialog.');
$assert(str_contains($time_row, 'data-time-entry-id="<?php echo (int) $entry[\'id\']; ?>"'), 'Extracted time entry rows must remain removable after quick delete.');
$assert(str_contains($app_footer_asset, 'options.actionLabel'), 'App toast must support an action button for Undo.');
$assert(str_contains($app_footer_asset, 'options.onAction'), 'App toast must execute Undo action callbacks.');

foreach ([
    'window.FoxDeskRichText',
    'getSemanticHTML',
    'fieldValue',
    'loadHtml',
    'isBlankHtml',
] as $richTextNeedle) {
    $assert(str_contains($rich_text_asset, $richTextNeedle), 'Rich text editor asset missing behavior: ' . $richTextNeedle);
}

foreach ([
    'data.file.url',
    "image.php?f=' + encodeURIComponent(data.file.filename)",
    'createPreviewUrl(file)',
    "quill.insertEmbed(index, 'image', previewUrl, 'user')",
    'replacePreviewImage(quill, previewUrl, imgUrl',
    'rich-inline-image--uploading',
    'URL.createObjectURL',
    'URL.revokeObjectURL',
    'allowBlobImageSources',
    "indexOf('blob:') === 0",
    "img[src=\"//:0\"]",
] as $quillUploadNeedle) {
    $assert(str_contains($quill_upload_asset, $quillUploadNeedle), 'Quill image upload asset missing behavior: ' . $quillUploadNeedle);
}

foreach ([
    'window.openImagePreview',
    'window.closeImagePreview',
    'data-image-preview-trigger',
    '.ql-editor img',
    '.rich-content img.rich-inline-image',
    'image-lightbox',
    '}, true);',
] as $imagePreviewNeedle) {
    $assert(str_contains($image_preview_asset, $imagePreviewNeedle), 'Image preview asset missing behavior: ' . $imagePreviewNeedle);
}

foreach ([
    'URL.createObjectURL',
    'URL.revokeObjectURL',
    'upload-preview-card--image',
    'data-image-preview-trigger',
    'data-image-preview-src',
] as $uploadPreviewNeedle) {
    $assert(str_contains($upload_preview_asset, $uploadPreviewNeedle), 'Upload preview asset missing image thumbnail behavior: ' . $uploadPreviewNeedle);
}

foreach ([
    'data-image-preview-trigger',
    'data-image-preview-src',
    'data-image-preview-name',
] as $attachmentPreviewNeedle) {
    $assert(str_contains($attachment_grid, $attachmentPreviewNeedle), 'Attachment grid missing image preview contract: ' . $attachmentPreviewNeedle);
}

foreach ([
    'assets/js/image-preview.js',
    'class="image-lightbox"',
    'data-image-preview-close',
] as $footerPreviewNeedle) {
    $assert(str_contains($footer, $footerPreviewNeedle), 'Footer missing shared image preview surface: ' . $footerPreviewNeedle);
}

foreach ([
    'require_csrf_token(true)',
    'can_see_ticket($ticket, $user)',
    '$visibility = $ticket_id > 0',
    'upload_file($_FILES',
] as $uploadNeedle) {
    $assert(str_contains($upload_handler, $uploadNeedle), 'Self-hosted upload handler missing permission boundary: ' . $uploadNeedle);
}

$assert(str_contains($asset, 'if (config.quickStart)'), 'Self-hosted quick-start must remain supported.');
$sidebar = file_get_contents($root . '/includes/components/ticket-detail-sidebar.php');
$assert(!str_contains($sidebar, 'current_tenant_id()'), 'Self-hosted sidebar must not depend on SaaS tenancy.');

foreach ([
    'window.FoxDeskAttachmentPasteDrop',
    'function autoBindKnownSurfaces',
    'function shouldSkipEvent',
    "var excluded = ['.ql-editor']",
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
    '.image-lightbox',
    '.upload-preview-card--image',
    '.upload-preview-card__thumb',
    '#comment-editor .ql-editor img',
    '.ql-editor img.rich-inline-image--uploading',
    'cursor: zoom-in',
] as $themeNeedle) {
    $assert(str_contains($theme, $themeNeedle), 'Theme CSS missing ticket timeline state: ' . $themeNeedle);
}

echo "Ticket detail JS contract OK\n";
