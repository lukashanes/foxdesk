<?php
/**
 * Compact workspace UI components.
 *
 * These helpers keep the daily work screens consistent without mixing queue
 * query logic into the presentation layer.
 */

function workspace_surface_action(string $url, string $label, string $icon = 'plus', string $class = 'btn btn-primary btn-sm'): string
{
    return '<a href="' . e($url) . '" class="' . e($class) . '">'
        . get_icon($icon, 'w-4 h-4 mr-1')
        . e(t($label))
        . '</a>';
}

function workspace_render_queue_page(array $options): void
{
    $title = (string) ($options['title'] ?? 'Work');
    $summary = is_array($options['summary'] ?? null) ? $options['summary'] : [];
    $active_key = (string) ($options['active_key'] ?? '');
    $active_queue = is_array($options['active_queue'] ?? null) ? $options['active_queue'] : [];
    $active_items = is_array($options['items'] ?? null) ? $options['items'] : [];
    $queue_url = $options['queue_url'] ?? null;
    $view_all_url = (string) ($options['view_all_url'] ?? url('tickets'));
    $primary_action = (string) ($options['primary_action'] ?? workspace_surface_action(url('new-ticket'), 'New ticket'));
    $row_options = is_array($options['row_options'] ?? null) ? $options['row_options'] : [];
    $aria_label = (string) ($options['aria_label'] ?? $title);
    $contract_surface = (string) ($options['contract_surface'] ?? 'work');
    $contract_collection = (string) ($options['contract_collection'] ?? $contract_surface);
    ?>
    <div class="workspace-queue-page"
         data-workspace-queue-surface
         data-app-contract-surface="<?php echo e($contract_surface); ?>"
         data-app-contract-collection="<?php echo e($contract_collection); ?>"
         data-app-contract-action="app-home"
         data-app-contract-limit="<?php echo (int) ($options['contract_limit'] ?? 6); ?>"
         data-work-active-key="<?php echo e($active_key); ?>"
         data-work-empty-label="<?php echo e(t('All clear')); ?>"
         data-work-show-assignee="<?php echo !empty($row_options['show_assignee']) ? '1' : '0'; ?>"
         data-work-show-source="<?php echo !empty($row_options['show_source']) ? '1' : '0'; ?>">
        <div class="workspace-surface-head">
            <h1 class="workspace-surface-title"><?php echo e(t($title)); ?></h1>
            <div class="workspace-surface-actions">
                <?php echo $primary_action; ?>
            </div>
        </div>

        <div class="workspace-queue-shell">
            <nav class="workspace-queue-rail" aria-label="<?php echo e(t($aria_label)); ?>">
                <?php foreach ($summary as $key => $queue): ?>
                    <?php
                    $definition = is_array($queue['definition'] ?? null) ? $queue['definition'] : [];
                    $label = (string) ($definition['label'] ?? ucfirst((string) $key));
                    $href = is_callable($queue_url) ? (string) $queue_url((string) $key) : '#';
                    ?>
                    <a href="<?php echo e($href); ?>"
                       class="workspace-queue-link <?php echo $key === $active_key ? 'is-active' : ''; ?>"
                       data-work-queue-key="<?php echo e((string) $key); ?>"
                       <?php echo $key === $active_key ? 'aria-current="page"' : ''; ?>>
                        <span class="workspace-queue-label"><?php echo e(t($label)); ?></span>
                        <span class="workspace-queue-count" data-work-queue-count><?php echo (int) ($queue['count'] ?? 0); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <section class="workspace-queue-panel">
                <div class="workspace-queue-panel__head">
                    <h2 class="workspace-queue-panel__title" data-work-active-title>
                        <?php echo e(t((string) ($active_queue['definition']['label'] ?? $title))); ?>
                    </h2>
                    <a href="<?php echo e($view_all_url); ?>" class="btn btn-secondary btn-sm"><?php echo e(t('View all')); ?></a>
                </div>

                <?php workspace_render_ticket_rows($active_items, $row_options); ?>
            </section>
        </div>
    </div>
    <?php
}

function workspace_render_ticket_rows(array $tickets, array $options = []): void
{
    ?>
    <div class="workspace-ticket-list" data-work-ticket-list>
        <?php if (empty($tickets)): ?>
            <div class="workspace-empty" data-work-empty>
                <div class="workspace-empty__title"><?php echo e(t('All clear')); ?></div>
            </div>
        <?php else: ?>
            <?php foreach ($tickets as $ticket): ?>
                <?php workspace_render_ticket_row($ticket, $options); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}

function workspace_render_ticket_row(array $ticket, array $options = []): void
{
    $status_name = trim((string) ($ticket['status_name'] ?? ''));
    $organization = trim((string) ($ticket['organization_name'] ?? ''));
    $source = trim((string) ($ticket['source'] ?? ''));
    $assignee = trim((string) (($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? '')));
    $show_assignee = !empty($options['show_assignee']);
    $show_source = !empty($options['show_source']);
    $date_value = (string) ($ticket['updated_at'] ?? $ticket['created_at'] ?? '');
    ?>
    <a href="<?php echo e(ticket_url($ticket)); ?>" class="workspace-ticket-row">
        <div class="workspace-ticket-row__main">
            <div class="workspace-ticket-row__meta">
                <span class="workspace-ticket-row__dot" aria-hidden="true"></span>
                <span><?php echo e(get_ticket_code((int) ($ticket['id'] ?? 0))); ?></span>
                <?php if ($status_name !== ''): ?><span><?php echo e($status_name); ?></span><?php endif; ?>
                <?php if ($organization !== ''): ?><span><?php echo e($organization); ?></span><?php endif; ?>
                <?php if ($show_source && $source !== ''): ?><span><?php echo e($source); ?></span><?php endif; ?>
            </div>
            <div class="workspace-ticket-row__title"><?php echo e($ticket['title'] ?? ''); ?></div>
        </div>
        <div class="workspace-ticket-row__side">
            <?php if ($show_assignee && $assignee !== ''): ?>
                <span><?php echo e($assignee); ?></span>
            <?php endif; ?>
            <?php if ($date_value !== ''): ?>
                <span><?php echo e(format_date($date_value, 'd.m.Y')); ?></span>
            <?php endif; ?>
        </div>
    </a>
    <?php
}
