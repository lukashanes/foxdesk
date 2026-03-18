<?php
/**
 * Alert component (inline, non-dismissible)
 * Expected variables:
 * - $alert_message (string, required)
 * - $alert_type (string): 'error'|'success'|'info'|'warning' (default: 'error')
 */
if (empty($alert_message)) return;
$alert_type = $alert_type ?? 'error';
?>
<div class="alert alert-<?php echo e($alert_type); ?>" role="alert">
    <?php echo e($alert_message); ?>
</div>

