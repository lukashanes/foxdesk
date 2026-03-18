<?php
/**
 * Empty state component
 * Expected variables:
 * - $empty_title (string, required)
 * - $empty_message (string, optional)
 * - $empty_icon (string, optional, Font Awesome class)
 * - $empty_action_label (string, optional)
 * - $empty_action_url (string, optional)
 */
?>
<div class="empty-state">
    <?php if (!empty($empty_icon)): ?>
        <div class="empty-state__icon">
            <?php echo get_icon($empty_icon); ?>
        </div>
    <?php endif; ?>
    <div class="empty-state__title"><?php echo e($empty_title); ?></div>
    <?php if (!empty($empty_message)): ?>
        <div class="empty-state__message"><?php echo e($empty_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($empty_action_label) && !empty($empty_action_url)): ?>
        <a href="<?php echo e($empty_action_url); ?>" class="btn btn-primary btn-sm empty-state__action">
            <?php echo e($empty_action_label); ?>
        </a>
    <?php endif; ?>
</div>
