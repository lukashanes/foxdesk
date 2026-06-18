<?php
/**
 * Admin settings tab navigation.
 */

function admin_settings_tabs(): array
{
    return [
        'general' => ['label' => t('General'), 'icon' => 'cog'],
        'email' => ['label' => t('Emails'), 'icon' => 'envelope'],
        'templates' => ['label' => t('Templates'), 'icon' => 'file-alt'],
        'workflow' => ['label' => t('Workflow'), 'icon' => 'tasks'],
        'system' => ['label' => t('System'), 'icon' => 'desktop'],
        'logs' => ['label' => t('Logs'), 'icon' => 'list-alt'],
        'security' => ['label' => t('Security'), 'icon' => 'shield'],
    ];
}

function render_admin_settings_tabs(string $active_tab): void
{
    ?>
    <div class="admin-tabs" aria-label="<?php echo e(t('Settings sections')); ?>">
        <?php foreach (admin_settings_tabs() as $tab_key => $tab_meta): ?>
            <?php $is_active_tab = $active_tab === $tab_key; ?>
            <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => $tab_key]); ?>"
                class="admin-tab <?php echo $is_active_tab ? 'is-active' : ''; ?>"
                <?php echo $is_active_tab ? 'aria-current="page"' : ''; ?>>
                <?php echo get_icon($tab_meta['icon'], 'w-3.5 h-3.5'); ?>
                <span><?php echo e($tab_meta['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}
