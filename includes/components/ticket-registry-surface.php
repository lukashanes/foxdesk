<?php
/**
 * Ticket registry presentation helpers.
 *
 * Keep the ticket list chrome small and reusable. Ticket query rules stay in
 * the ticket modules; this file only renders view navigation and filter state.
 */

function ticket_registry_render_view_tabs(array $definitions, array $counts, string $active_view, bool $include_archive, array $request): void
{
    ?>
    <nav class="ticket-view-tabs" aria-label="<?php echo e(t('Ticket views')); ?>">
        <?php foreach ($definitions as $view_key => $view_definition): ?>
            <?php
            $view_url = ticket_list_view_url((string) $view_key, $request, $include_archive);
            $is_active_view = $active_view === (string) $view_key;
            $view_count = (int) ($counts[$view_key] ?? 0);
            ?>
            <a href="<?php echo e($view_url); ?>"
               class="ticket-view-tab <?php echo $is_active_view ? 'is-active' : ''; ?>"
               data-ticket-view-key="<?php echo e((string) $view_key); ?>"
               aria-current="<?php echo $is_active_view ? 'page' : 'false'; ?>"
               title="<?php echo e(t((string) ($view_definition['description'] ?? ''))); ?>">
                <span><?php echo e(t((string) ($view_definition['label'] ?? ucfirst((string) $view_key)))); ?></span>
                <span class="ticket-view-tab__count" data-ticket-view-count><?php echo e((string) $view_count); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function ticket_registry_render_filter_summary(int $total_tickets, array $filter_notes, string $clear_url, bool $has_filters): void
{
    if (empty($filter_notes) && !$has_filters) {
        return;
    }
    ?>
    <div class="ticket-filter-summary">
        <?php if (!empty($filter_notes)): ?>
            <div class="ticket-filter-summary__chips" aria-label="<?php echo e(t('Active filters')); ?>">
                <?php foreach ($filter_notes as $note): ?>
                    <span class="ticket-filter-chip"><?php echo e((string) $note); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($has_filters): ?>
            <a href="<?php echo e($clear_url); ?>" class="ticket-filter-summary__clear">
                <?php echo get_icon('x', 'w-3.5 h-3.5'); ?>
                <?php echo e(t('Clear')); ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
}
