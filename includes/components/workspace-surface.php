<?php
/**
 * Compact workspace UI components.
 *
 * These helpers keep the daily work screens consistent without mixing queue
 * query logic into the presentation layer.
 */

function workspace_surface_action(string $url, string $label, string $icon = 'plus', string $class = 'btn btn-primary btn-sm fd-button fd-button--primary fd-button--sm'): string
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
    $primary_action = array_key_exists('primary_action', $options)
        ? (string) $options['primary_action']
        : workspace_surface_action(url('new-ticket'), 'New ticket');
    $row_options = is_array($options['row_options'] ?? null) ? $options['row_options'] : [];
    $variant = trim((string) ($options['variant'] ?? ''));
    $subtitle = trim((string) ($options['subtitle'] ?? ''));
    $is_canvas = $variant === 'canvas';
    $aria_label = (string) ($options['aria_label'] ?? $title);
    $contract_surface = (string) ($options['contract_surface'] ?? 'work');
    $contract_collection = (string) ($options['contract_collection'] ?? $contract_surface);
    ?>
    <div class="workflow-surface workflow-surface--queue workspace-queue-page <?php echo $is_canvas ? 'workspace-queue-page--canvas' : ''; ?>"
         data-core-workflow-surface="work"
         data-workspace-queue-surface
         data-app-contract-surface="<?php echo e($contract_surface); ?>"
         data-app-contract-collection="<?php echo e($contract_collection); ?>"
         data-app-contract-action="app-home"
         data-app-contract-limit="<?php echo (int) ($options['contract_limit'] ?? 6); ?>"
         data-work-active-key="<?php echo e($active_key); ?>"
         data-work-layout="<?php echo $is_canvas ? 'table' : 'list'; ?>"
         data-work-empty-label="<?php echo e(t('All clear')); ?>"
         data-work-show-assignee="<?php echo !empty($row_options['show_assignee']) ? '1' : '0'; ?>"
         data-work-show-source="<?php echo !empty($row_options['show_source']) ? '1' : '0'; ?>">
        <div class="workspace-surface-head">
            <div class="workspace-surface-heading">
                <h2 class="workspace-surface-title"><?php echo e(t($title)); ?></h2>
                <?php if ($subtitle !== ''): ?>
                    <p class="workspace-surface-subtitle"><?php echo e(t($subtitle)); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($primary_action !== ''): ?>
                <div class="workspace-surface-actions">
                    <?php echo $primary_action; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="workspace-queue-shell">
            <nav class="fd-card fd-card--compact workspace-queue-rail" aria-label="<?php echo e(t($aria_label)); ?>">
                <?php foreach ($summary as $key => $queue): ?>
                    <?php
                    $definition = is_array($queue['definition'] ?? null) ? $queue['definition'] : [];
                    $label = (string) ($definition['label'] ?? ucfirst((string) $key));
                    $href = is_callable($queue_url) ? (string) $queue_url((string) $key) : '#';
                    $queue_icon = [
                        'mine' => 'user',
                        'unassigned' => 'plus',
                        'overdue' => 'clock',
                        'waiting' => 'pause',
                        'done_today' => 'check',
                    ][$key] ?? 'ticket-alt';
                    ?>
                    <a href="<?php echo e($href); ?>"
                       class="workspace-queue-link <?php echo $key === $active_key ? 'is-active' : ''; ?>"
                       data-work-queue-key="<?php echo e((string) $key); ?>"
                       <?php echo $key === $active_key ? 'aria-current="page"' : ''; ?>>
                        <?php if ($is_canvas): ?>
                            <span class="workspace-queue-icon" aria-hidden="true"><?php echo get_icon($queue_icon, 'w-4 h-4'); ?></span>
                        <?php endif; ?>
                        <span class="workspace-queue-label"><?php echo e(t($label)); ?></span>
                        <span class="workspace-queue-count" data-work-queue-count><?php echo (int) ($queue['count'] ?? 0); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <section class="fd-card fd-card--compact workspace-queue-panel">
                <div class="workspace-queue-panel__head">
                    <div>
                        <h2 class="workspace-queue-panel__title" data-work-active-title>
                            <?php echo e(t((string) ($active_queue['definition']['label'] ?? $title))); ?>
                        </h2>
                        <?php if ($is_canvas): ?>
                            <p class="workspace-queue-panel__meta">
                                <?php echo (int) ($active_queue['count'] ?? count($active_items)); ?> <?php echo e(t('Tickets')); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php if (is_callable($options['render_mode_switch'] ?? null)) ($options['render_mode_switch'])(); ?>
                    <a href="<?php echo e($view_all_url); ?>" class="btn btn-secondary btn-sm fd-button fd-button--secondary fd-button--sm"><?php echo e(t('View all')); ?></a>
                </div>

                <?php if ($is_canvas && !is_callable($options['render_content'] ?? null)): ?>
                    <div class="workspace-ticket-table-head" aria-hidden="true">
                        <span><?php echo e(t('Ticket')); ?></span>
                        <span><?php echo e(t('Subject')); ?></span>
                        <span><?php echo e(t('Client')); ?></span>
                        <span><?php echo e(t('Status')); ?></span>
                        <span><?php echo e(t('Updated')); ?></span>
                        <span><?php echo e(t('Time')); ?></span>
                        <span></span>
                    </div>
                <?php endif; ?>
                <?php if (is_callable($options['render_content'] ?? null)) ($options['render_content'])();
                else workspace_render_ticket_rows($active_items, array_merge($row_options, $is_canvas ? ['layout' => 'table'] : [])); ?>
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
    $status_name = function_exists('ticket_status_group_display_name')
        ? ticket_status_group_display_name([
            'name' => (string) ($ticket['status_name'] ?? ''),
            'is_closed' => $ticket['is_closed'] ?? 0,
        ])
        : trim((string) ($ticket['status_name'] ?? ''));
    $organization = trim((string) ($ticket['organization_name'] ?? ''));
    $source = trim((string) ($ticket['source'] ?? ''));
    $assignee = trim((string) (($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? '')));
    $show_assignee = !empty($options['show_assignee']);
    $show_source = !empty($options['show_source']);
    $date_value = (string) ($ticket['updated_at'] ?? $ticket['created_at'] ?? '');
    $worked_minutes = max(0, (int) ($ticket['worked_minutes'] ?? 0));
    $priority = trim((string) ($ticket['priority_name'] ?? ''));
    $priority_key = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $priority));
    $table_layout = ($options['layout'] ?? '') === 'table';
    ?>
    <?php if ($table_layout): ?>
    <a href="<?php echo e(ticket_url($ticket)); ?>" class="workspace-ticket-row workspace-ticket-row--table">
        <span class="workspace-ticket-cell workspace-ticket-cell--ticket" data-label="<?php echo e(t('Ticket')); ?>">
            <strong><?php echo e(get_ticket_code((int) ($ticket['id'] ?? 0))); ?></strong>
        </span>
        <span class="workspace-ticket-cell workspace-ticket-cell--subject" data-label="<?php echo e(t('Subject')); ?>">
            <strong><?php echo e($ticket['title'] ?? ''); ?></strong>
            <?php if ($show_assignee && $assignee !== ''): ?><small><?php echo e($assignee); ?></small><?php endif; ?>
        </span>
        <span class="workspace-ticket-cell" data-label="<?php echo e(t('Client')); ?>"><?php echo $organization !== '' ? e($organization) : '<span aria-hidden="true">—</span>'; ?></span>
        <span class="workspace-ticket-cell workspace-ticket-cell--status" data-label="<?php echo e(t('Status')); ?>">
            <span class="workspace-status-pill" style="--workspace-status-color: <?php echo e(preg_match('/^#[0-9a-f]{6}$/i', $ticket['status_color'] ?? '') ? $ticket['status_color'] : '#5279c5'); ?>"><?php echo $status_name !== '' ? e($status_name) : '—'; ?></span>
        </span>
        <span class="workspace-ticket-cell" data-label="<?php echo e(t('Updated')); ?>"><?php echo $date_value !== '' ? e(format_date($date_value)) : '<span aria-hidden="true">—</span>'; ?></span>
        <span class="workspace-ticket-cell workspace-ticket-cell--time" data-label="<?php echo e(t('Time')); ?>">
            <?php echo get_icon('clock', 'w-3.5 h-3.5'); ?>
            <span><?php echo e(format_duration_minutes($worked_minutes)); ?></span>
        </span>
        <span class="workspace-ticket-cell workspace-ticket-cell--arrow" aria-hidden="true"><?php echo get_icon('chevron-right', 'w-4 h-4'); ?></span>
    </a>
    <?php else: ?>
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
                <div><?php echo e($assignee); ?></div>
            <?php endif; ?>
            <?php if ($date_value !== ''): ?>
                <div><?php echo e(format_date($date_value, 'd.m.Y')); ?></div>
            <?php endif; ?>
            <?php if ($worked_minutes > 0): ?>
                <div class="workspace-ticket-row__time">
                    <?php echo get_icon('clock', 'w-3.5 h-3.5'); ?>
                    <span><?php echo e(format_duration_minutes($worked_minutes)); ?></span>
                </div>
            <?php endif; ?>
        </div>
    </a>
    <?php endif; ?>
    <?php
}
