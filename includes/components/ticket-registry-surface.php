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
        <?php $tab_keys = ticket_list_view_tab_keys($active_view, $include_archive); ?>
        <?php foreach ($definitions as $view_key => $view_definition): ?>
            <?php if (!in_array((string) $view_key, $tab_keys, true)) { continue; } ?>
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

/**
 * Server-side list paging: page size links and previous/next, preserving
 * every active filter. Rendered only when the list has more than one page.
 */
function ticket_registry_render_pagination(array $paging, array $request, string $label): void
{
    if ((int) ($paging['pages'] ?? 1) <= 1 && (string) ($paging['size'] ?? '20') !== 'all') {
        return;
    }
    $params = $request;
    unset($params['page']);
    $link = static function (array $changes) use ($params): string {
        return url('tickets', array_merge($params, $changes));
    };
    ?>
    <nav class="registry-pagination" aria-label="<?php echo e($label); ?>" data-registry-pagination>
        <span class="registry-pagination__summary"><?php echo (int) $paging['page']; ?> / <?php echo (int) $paging['pages']; ?> · <?php echo (int) $paging['count']; ?> <?php echo e($label); ?></span>
        <span class="registry-pagination__size">
            <?php foreach (['10', '20', '50', 'all'] as $size): ?>
                <a class="btn btn-secondary btn-sm" href="<?php echo e($link(['page_size' => $size, 'p' => 1])); ?>" <?php echo $size === (string) $paging['size'] ? 'aria-current="page"' : ''; ?>><?php echo e($size === 'all' ? t('All') : $size); ?></a>
            <?php endforeach; ?>
        </span>
        <?php foreach ([-1 => t('Previous'), 1 => t('Next')] as $step => $step_label): ?>
            <?php $target = (int) $paging['page'] + $step; ?>
            <?php if ($target >= 1 && $target <= (int) $paging['pages']): ?>
                <a class="btn btn-secondary btn-sm" href="<?php echo e($link(['p' => $target, 'page_size' => (string) $paging['size']])); ?>" data-registry-<?php echo $step > 0 ? 'next' : 'prev'; ?>><?php echo e($step_label); ?></a>
            <?php else: ?>
                <button type="button" class="btn btn-secondary btn-sm" disabled><?php echo e($step_label); ?></button>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
}
