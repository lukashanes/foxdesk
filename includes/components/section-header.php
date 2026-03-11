<?php
/**
 * Section Header component — widget header with icon + action link
 * Expected variables:
 * - $sh_icon (string, required) — icon name for get_icon()
 * - $sh_title (string, required) — translated section title
 * - $sh_link_url (string, optional) — action link URL
 * - $sh_link_label (string, optional) — action link label text
 */
if (empty($sh_title)) return;
?>
<div class="db-section-header">
    <h3 class="db-section-title flex items-center gap-2">
        <?php if (!empty($sh_icon)): ?><?php echo get_icon($sh_icon, 'w-4 h-4'); ?><?php endif; ?>
        <?php echo e($sh_title); ?>
    </h3>
    <?php if (!empty($sh_link_url) && !empty($sh_link_label)): ?>
        <a href="<?php echo $sh_link_url; ?>" class="db-section-link"><?php echo e($sh_link_label); ?></a>
    <?php endif; ?>
</div>

