<?php
/**
 * Admin settings tab navigation.
 */

function admin_settings_tabs(): array
{
    $email_description = (function_exists('settings_email_is_managed_surface') && settings_email_is_managed_surface())
        ? t('Support address and delivery status.')
        : t('Outgoing and incoming email.');

    return [
        'general' => ['label' => t('Workspace'), 'description' => t('Name, language, time and invoice defaults.'), 'icon' => 'cog'],
        'email' => ['label' => t('Email'), 'description' => $email_description, 'icon' => 'envelope'],
        'templates' => ['label' => t('Templates'), 'description' => t('Customer-facing notification text.'), 'icon' => 'file-alt'],
        'workflow' => ['label' => t('Workflow'), 'description' => t('Statuses, priorities, and ticket rules.'), 'icon' => 'tasks'],
        'security' => ['label' => t('Security'), 'description' => t('Access, sessions, and protection settings.'), 'icon' => 'shield'],
        'system' => ['label' => t('System'), 'description' => t('Updates, backups, tasks, and upload limits.'), 'icon' => 'desktop'],
        'logs' => ['label' => t('Logs'), 'description' => t('Operational history and diagnostics.'), 'icon' => 'list-alt'],
    ];
}

function admin_settings_management_links(): array
{
    return [
        [
            'label' => t('Team & access'),
            'description' => t('Users, AI agents, roles, and API access.'),
            'icon' => 'users',
            'url' => url('admin', ['section' => 'users']),
        ],
        [
            'label' => t('Clients'),
            'description' => t('Client contacts and access.'),
            'icon' => 'user',
            'url' => url('admin', ['section' => 'clients']),
        ],
        [
            'label' => t('Companies'),
            'description' => t('Organizations, billing rates, and company details.'),
            'icon' => 'building',
            'url' => url('admin', ['section' => 'organizations']),
        ],
        [
            'label' => t('Ticket workflow'),
            'description' => t('Statuses, priorities, and ticket types.'),
            'icon' => 'tasks',
            'url' => url('admin', ['section' => 'statuses']),
        ],
        [
            'label' => t('Recurring tasks'),
            'description' => t('Scheduled tickets and maintenance work.'),
            'icon' => 'clock',
            'url' => url('admin', ['section' => 'recurring-tasks']),
        ],
        [
            'label' => t('Time reports'),
            'description' => t('Worked time, team totals, and billing review.'),
            'icon' => 'chart-bar',
            'url' => url('admin', ['section' => 'reports']),
        ],
        [
            'label' => t('Client reports'),
            'description' => t('Published client reports and shared exports.'),
            'icon' => 'file-alt',
            'url' => url('admin', ['section' => 'reports-list']),
        ],
        [
            'label' => t('Activity'),
            'description' => t('Admin activity and audit trail.'),
            'icon' => 'list-alt',
            'url' => url('admin', ['section' => 'activity']),
        ],
    ];
}

function render_admin_settings_management_links(): void
{
    ?>
    <section class="settings-management-panel" data-settings-management>
        <div class="fd-section-header settings-management-panel__head">
            <div class="fd-section-main">
                <h2 class="fd-section-title"><?php echo e(t('Admin areas')); ?></h2>
            </div>
        </div>
        <div class="settings-section-nav settings-section-nav--management">
            <?php foreach (admin_settings_management_links() as $item): ?>
                <a href="<?php echo e($item['url']); ?>" class="settings-section-card" data-settings-management-link>
                    <span class="settings-section-card__icon"><?php echo get_icon($item['icon'], 'w-4 h-4'); ?></span>
                    <span class="settings-section-card__body">
                        <strong><?php echo e($item['label']); ?></strong>
                        <span><?php echo e($item['description']); ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

function render_admin_settings_tabs(string $active_tab): void
{
    ?>
    <div class="settings-section-nav" aria-label="<?php echo e(t('Settings sections')); ?>">
        <?php foreach (admin_settings_tabs() as $tab_key => $tab_meta): ?>
            <?php $is_active_tab = $active_tab === $tab_key; ?>
            <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => $tab_key]); ?>"
                class="settings-section-card <?php echo $is_active_tab ? 'is-active' : ''; ?>"
                <?php echo $is_active_tab ? 'aria-current="page"' : ''; ?>>
                <span class="settings-section-card__icon"><?php echo get_icon($tab_meta['icon'], 'w-4 h-4'); ?></span>
                <span class="settings-section-card__body">
                    <strong><?php echo e($tab_meta['label']); ?></strong>
                    <span><?php echo e($tab_meta['description'] ?? ''); ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}
