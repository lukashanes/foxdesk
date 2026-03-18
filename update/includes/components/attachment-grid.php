<?php
/**
 * Attachment Grid Component
 *
 * Renders a grid of attachments with image thumbnails and file pills.
 * Images get clickable thumbnails that open a lightbox preview.
 * Non-image files get icon + name pills.
 *
 * Usage:
 *   $component_attachments = $my_attachments;
 *   $component_layout = 'inline';  // 'inline' (comment) | 'grid' (ticket description)
 *   include BASE_PATH . '/includes/components/attachment-grid.php';
 *
 * Variables:
 *   $component_attachments  — array of attachment rows
 *   $component_layout       — 'inline' or 'grid' (default: 'inline')
 */

$_layout = $component_layout ?? 'inline';
$_images = [];
$_files  = [];

foreach ($component_attachments as $_att) {
    if (is_image_mime($_att['mime_type'] ?? '')) {
        $_images[] = $_att;
    } else {
        $_files[] = $_att;
    }
}
?>

<?php if (!empty($_images)): ?>
<div class="<?php echo $_layout === 'grid' ? 'grid grid-cols-2 sm:grid-cols-3 gap-2' : 'flex flex-wrap gap-2'; ?> mt-2">
    <?php foreach ($_images as $_img): ?>
        <?php $_src = upload_url(UPLOAD_DIR . $_img['filename']); ?>
        <a href="<?php echo e($_src); ?>" target="_blank"
           class="attachment-thumb group relative block rounded-lg overflow-hidden border hover:shadow-md transition"
           style="border-color: var(--border-light);"
           onclick="event.preventDefault(); openImagePreview('<?php echo e($_src); ?>', '<?php echo e($_img['original_name']); ?>');">
            <img src="<?php echo e($_src); ?>" alt="<?php echo e($_img['original_name']); ?>"
                 class="object-cover"
                 style="<?php echo $_layout === 'grid' ? 'width:100%;height:8rem;' : 'width:6rem;height:5rem;'; ?>"
                 loading="lazy">
            <span class="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/30 transition">
                <span class="hidden group-hover:inline-flex text-white text-xs font-medium bg-black/50 px-2 py-1 rounded">
                    <?php echo get_icon('search-plus', 'w-3.5 h-3.5 mr-1'); ?><?php echo e(t('Preview')); ?>
                </span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($_files)): ?>
<div class="flex flex-wrap gap-2 <?php echo !empty($_images) ? 'mt-2' : 'mt-2'; ?>">
    <?php foreach ($_files as $_f): ?>
        <a href="<?php echo e(upload_url(UPLOAD_DIR . $_f['filename'])); ?>" target="_blank"
           class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition hover:shadow-sm"
           style="background: var(--surface-secondary); color: var(--text-secondary); border: 1px solid var(--border-light);">
            <?php echo get_icon(get_file_icon($_f['mime_type']), 'w-3.5 h-3.5 flex-shrink-0'); ?>
            <span class="truncate max-w-[140px]"><?php echo e($_f['original_name']); ?></span>
            <span class="text-xs" style="color: var(--text-muted);"><?php echo format_file_size($_f['file_size']); ?></span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
