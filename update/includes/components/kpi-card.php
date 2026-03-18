<?php
/**
 * KPI Card component — colored stat card for dashboard overview
 * Expected variables:
 * - $kpi_href (string, required) — link URL
 * - $kpi_color (string, required) — color name: blue, red, amber, green, slate
 * - $kpi_label (string, required) — translated label text
 * - $kpi_value (string|int, required) — main numeric value
 * - $kpi_value_class (string, optional) — extra class on value (e.g. 'text-red-600')
 * - $kpi_sub (string, optional) — subtitle text (HTML allowed)
 * - $kpi_pulse (bool, optional) — show pulse dot after label
 */
if (empty($kpi_href) || !isset($kpi_value)) return;
$kpi_value_class = $kpi_value_class ?? '';
$kpi_sub = $kpi_sub ?? '';
$kpi_pulse = $kpi_pulse ?? false;
?>
<a href="<?php echo $kpi_href; ?>" class="db-kpi db-kpi--<?php echo e($kpi_color); ?>">
    <div class="db-kpi__label"><?php echo e($kpi_label); ?><?php if ($kpi_pulse): ?><span class="db-pulse-dot"></span><?php endif; ?></div>
    <div class="db-kpi__value <?php echo e($kpi_value_class); ?>"><?php echo $kpi_value; ?></div>
    <?php if ($kpi_sub !== ''): ?>
        <div class="db-kpi__sub"><?php echo $kpi_sub; ?></div>
    <?php endif; ?>
</a>

