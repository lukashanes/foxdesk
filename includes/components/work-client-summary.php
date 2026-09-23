<?php /** Variables come from the dashboard's tenant-scoped saved-time read model. */ ?>
<section class="work-client-summary fd-card" data-work-client-summary>
    <div class="work-client-summary__head">
        <h2><?php echo e(t('Clients')); ?></h2>
        <span><?php echo count($dashboard_clients['clients']); ?></span>
    </div>
    <p class="work-client-summary__scope"><?php echo e($selected_period_label . ' · ' . $time_view_scope['label']); ?></p>
    <?php if (count($dashboard_clients['clients']) > 6): ?>
        <input class="form-input" type="search" data-work-client-search placeholder="<?php echo e(t('Search')); ?>" aria-label="<?php echo e(t('Clients')); ?>">
    <?php endif; ?>
    <div class="work-client-summary__scroll" role="region" aria-label="<?php echo e(t('Clients')); ?>">
        <table class="fd-table">
            <thead><tr><th><?php echo e(t('Client')); ?></th><th><?php echo e(t('Today')); ?></th><th><?php echo e($selected_period_label); ?></th></tr></thead>
            <tbody>
            <?php foreach ($dashboard_clients['clients'] as $client_index => $client): ?>
                <tr data-work-client-row <?php echo $client_index >= 6 ? 'hidden' : ''; ?>>
                    <td><?php if ($client['id'] > 0 && $is_staff): ?><a href="<?php echo e(url('client', ['id' => $client['id']])); ?>"><?php endif; ?><bdi dir="auto"><?php echo e($client['name']); ?></bdi><?php if ($client['id'] > 0 && $is_staff): ?></a><?php endif; ?></td>
                    <td><?php echo e(format_duration_minutes($client['today'])); ?></td>
                    <td><?php echo e(format_duration_minutes($client['selected'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$dashboard_clients['clients']): ?><tr><td colspan="3"><?php echo e(t('No activity')); ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($dashboard_clients['clients']) > 6): ?>
        <nav class="work-queue-pagination" aria-label="<?php echo e(t('Clients')); ?>">
            <span data-work-client-page>1 / <?php echo (int) ceil(count($dashboard_clients['clients']) / 6); ?></span>
            <button type="button" class="btn btn-secondary btn-sm" data-work-client-step="-1" disabled><?php echo e(t('Previous')); ?></button>
            <button type="button" class="btn btn-secondary btn-sm" data-work-client-step="1"><?php echo e(t('Next')); ?></button>
        </nav>
    <?php endif; ?>
</section>
