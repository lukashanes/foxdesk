<?php
/**
 * Widget Wrapper (opening) — dashboard widget container with drag support
 * Expected variables:
 * - $ww_id (string, required) — widget ID for data-widget attribute
 * - $ww_size (string, required) — 'half' or 'full'
 * - $ww_hidden (bool, optional) — whether widget is hidden
 *
 * Usage: include this, then your widget content, then include widget-wrap-close.php
 */
if (empty($ww_id)) return;
$ww_hidden = $ww_hidden ?? false;
$ww_hide_style = $ww_hidden ? ' style="display:none"' : '';
?>
<div class="db-widget" data-widget="<?php echo e($ww_id); ?>" data-size="<?php echo e($ww_size); ?>" draggable="true"<?php echo $ww_hide_style; ?>>
    <div class="card card-body">

