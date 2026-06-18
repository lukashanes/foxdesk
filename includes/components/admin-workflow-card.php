<?php
/**
 * Admin workflow card renderer.
 */

function render_admin_workflow_card(array $card): void
{
    $title = (string) ($card['title'] ?? '');
    $subtitle = (string) ($card['subtitle'] ?? '');
    $icon = (string) ($card['icon'] ?? 'circle');
    $color = (string) ($card['color'] ?? 'var(--text-primary)');
    $content = (string) ($card['content'] ?? '');
    $modifier = (string) ($card['modifier'] ?? '');
    ?>
    <div class="workflow-card">
        <div class="workflow-card-header">
            <span class="workflow-card-title <?php echo $modifier !== '' ? 'workflow-card-title--' . e($modifier) : ''; ?>"
                  style="color: <?php echo e($color); ?>; font-weight: 600;">
                <?php echo get_icon($icon, 'w-4 h-4 inline mr-2'); ?>
                <?php echo e(t($title)); ?>
            </span>
            <?php if ($subtitle !== ''): ?>
                <p class="workflow-card-subtitle text-xs">
                    <?php echo e(t($subtitle)); ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="workflow-card-body">
            <?php include $content; ?>
        </div>
    </div>
    <?php
}

function admin_workflow_cards(): array
{
    return [
        [
            'title' => 'Statuses',
            'subtitle' => 'Manage ticket statuses',
            'icon' => 'check-circle',
            'color' => '#3b82f6',
            'modifier' => 'statuses',
            'content' => BASE_PATH . '/pages/admin/statuses-content.php',
        ],
        [
            'title' => 'Priorities',
            'subtitle' => 'Manage ticket priorities',
            'icon' => 'arrow-up',
            'color' => '#f59e0b',
            'modifier' => 'priorities',
            'content' => BASE_PATH . '/pages/admin/priorities-content.php',
        ],
        [
            'title' => 'Ticket Types',
            'subtitle' => 'Manage ticket types',
            'icon' => 'file-alt',
            'color' => '#8b5cf6',
            'modifier' => 'types',
            'content' => BASE_PATH . '/pages/admin/ticket-types-content.php',
        ],
    ];
}
