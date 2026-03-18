<?php
/**
 * Colored Badge component — dynamic color badge for status/priority
 * Expected variables:
 * - $badge_label (string, required) — display text
 * - $badge_color (string, required) — hex color (e.g. '#ef4444')
 * - $badge_class (string, optional) — additional CSS classes
 */
if (empty($badge_label) || empty($badge_color)) return;
$badge_class = $badge_class ?? '';
?>
<span class="db-badge <?php echo e($badge_class); ?>" style="background-color: <?php echo e($badge_color); ?>20; color: <?php echo e($badge_color); ?>;">
    <?php echo e($badge_label); ?>
</span>

