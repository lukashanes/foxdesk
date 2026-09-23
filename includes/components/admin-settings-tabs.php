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
        'team' => ['label' => t('Team & access'), 'description' => t('Users, roles, and permissions.'), 'icon' => 'users', 'url' => url('admin', ['section' => 'users'])],
        'workflow' => ['label' => t('Workflow'), 'description' => t('Statuses, priorities, and ticket rules.'), 'icon' => 'tasks'],
        'email' => ['label' => t('Email'), 'description' => $email_description . ' ' . t('Templates'), 'icon' => 'envelope'],
        'api' => ['label' => t('API & agents'), 'description' => t('API keys, AI agents, and test requests.'), 'icon' => 'key'],
        'security' => ['label' => t('Security'), 'description' => t('Access, sessions, and protection settings.'), 'icon' => 'shield'],
    ];
}

/** Keep existing URLs and POST handlers; group their destinations, not their data. */
function admin_settings_group(string $tab): string
{
    return [
        'templates' => 'email', 'system' => 'security', 'logs' => 'security',
        'users' => 'team', 'recurring-tasks' => 'workflow',
    ][$tab] ?? (isset(admin_settings_tabs()[$tab]) ? $tab : 'general');
}

function admin_settings_subtabs(string $tab): array
{
    $group = admin_settings_group($tab);
    $sections = [
        'email' => ['email' => t('Email'), 'templates' => t('Templates')],
        'security' => ['security' => t('Security'), 'system' => t('System'), 'logs' => t('Activity')],
        'workflow' => ['workflow' => t('Workflow'), 'recurring-tasks' => t('Recurring Tasks')],
    ];
    return $sections[$group] ?? [];
}

function render_admin_settings_tabs(string $active_tab): void
{
    $active_group = admin_settings_group($active_tab);
    ?>
    <aside class="settings-navigation" data-settings-cancel-label="<?php echo e(t('Cancel')); ?>">
    <label class="settings-navigation__search">
        <?php echo get_icon('search', 'w-4 h-4'); ?>
        <input type="search" class="form-input" data-settings-search aria-label="<?php echo e(t('Search')); ?>" placeholder="<?php echo e(t('Search')); ?>">
    </label>
    <nav class="settings-section-nav" aria-label="<?php echo e(t('Settings sections')); ?>">
        <?php foreach (admin_settings_tabs() as $tab_key => $tab_meta): ?>
            <?php $is_active_tab = $active_group === $tab_key; ?>
            <a href="<?php echo e($tab_meta['url'] ?? url('admin', ['section' => 'settings', 'tab' => $tab_key])); ?>"
                data-settings-destination
                data-settings-search-text="<?php echo e($tab_meta['label'] . ' ' . $tab_meta['description'] . ' ' . implode(' ', admin_settings_subtabs($tab_key))); ?>"
                class="settings-section-card <?php echo $is_active_tab ? 'is-active' : ''; ?>"
                <?php echo $is_active_tab ? 'aria-current="page"' : ''; ?>>
                <span class="settings-section-card__icon"><?php echo get_icon($tab_meta['icon'], 'w-4 h-4'); ?></span>
                <span class="settings-section-card__body">
                    <strong><?php echo e($tab_meta['label']); ?></strong>
                    <span><?php echo e($tab_meta['description'] ?? ''); ?></span>
                </span>
            </a>
        <?php endforeach; ?>
        <a href="<?php echo e(url('profile')); ?>" class="settings-section-card settings-personal-link" data-settings-destination><?php echo get_icon('user', 'w-4 h-4'); ?><span class="settings-section-card__body"><strong><?php echo e(t('My profile')); ?></strong></span><?php echo get_icon('chevron-right', 'w-3 h-3'); ?></a>
    </nav>
    <div class="settings-search-results" data-settings-search-results hidden></div>
    <?php
    // Search only fields rendered for this edition, role and current connection state.
    // The browser reads these same-origin pages without submitting any form.
    $field_sections = [];
    foreach (['general', 'api', 'email', 'templates', 'workflow', 'system', 'logs', 'security'] as $section) {
        $field_sections[] = url('admin', ['section' => 'settings', 'tab' => $section]);
    }
    $field_sections[] = url('admin', ['section' => 'users']);
    $field_sections[] = url('admin', ['section' => 'recurring-tasks']);
    ?>
    <script type="application/json" data-settings-section-index><?php echo json_encode($field_sections, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
    </aside>
    <?php
}

function render_admin_settings_subtabs(string $active_tab): void
{
    $tabs = admin_settings_subtabs($active_tab);
    if (!$tabs) return;
    ?>
    <nav class="settings-subtabs fd-segmented" aria-label="<?php echo e(t('Settings sections')); ?>">
    <?php foreach ($tabs as $key => $label): ?>
        <?php $href = $key === 'recurring-tasks' ? url('admin', ['section' => $key]) : url('admin', ['section' => 'settings', 'tab' => $key]); ?>
        <a class="fd-segmented__item <?php echo $active_tab === $key ? 'is-active' : ''; ?>" href="<?php echo e($href); ?>" <?php echo $active_tab === $key ? 'aria-current="page"' : ''; ?>><?php echo e($label); ?></a>
    <?php endforeach; ?>
    </nav>
    <?php
}
