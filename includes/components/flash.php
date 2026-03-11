<?php
/**
 * Flash message component
 * Expected variable: $flash = ['message' => string, 'type' => 'success'|'error'|'info'|'warning']
 */
if (empty($flash)) {
    return;
}

$flash_type = $flash['type'] ?? 'success';
$flash_type_map = [
    'success' => 'flash-success',
    'error' => 'flash-error',
    'info' => 'flash-info',
    'warning' => 'flash-warning'
];
$flash_classes = $flash_type_map[$flash_type] ?? 'flash-info';
?>
<div class="flash-message <?php echo $flash_classes; ?>" role="status" aria-live="polite"
    data-flash-type="<?php echo e($flash_type); ?>">
    <div class="flex items-start justify-between gap-4">
        <div class="text-sm"><?php echo e($flash['message']); ?></div>
        <button type="button" class="flash-close" aria-label="Close">
            <?php echo get_icon('times'); ?>
        </button>
    </div>
</div>

