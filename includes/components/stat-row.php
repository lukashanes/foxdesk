<?php
/**
 * Stat Row component — clickable stat with optional colored dot
 * Expected variables:
 * - $sr_href (string, required) — link URL
 * - $sr_label (string, required) — label text
 * - $sr_value (string|int, required) — display value
 * - $sr_value_class (string, optional) — extra class on value element
 * - $sr_dot_color (string, optional) — hex color for dot indicator (e.g. '#ef4444')
 * - $sr_extra (string, optional) — extra HTML after value (e.g. pulse dot)
 */
if (empty($sr_href) || !isset($sr_value)) return;
$sr_value_class = $sr_value_class ?? '';
$sr_dot_color = $sr_dot_color ?? '';
$sr_extra = $sr_extra ?? '';
?>
<a href="<?php echo $sr_href; ?>" class="db-stat-row">
    <span class="db-stat-label">
        <?php if ($sr_dot_color !== ''): ?>
            <span class="db-stat-dot" style="background: <?php echo e($sr_dot_color); ?>;"></span>
        <?php endif; ?>
        <?php echo e($sr_label); ?>
    </span>
    <span class="db-stat-value <?php echo e($sr_value_class); ?>"><?php echo $sr_value; ?><?php echo $sr_extra; ?></span>
</a>

