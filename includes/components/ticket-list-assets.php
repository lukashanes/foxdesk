<?php if ($ticket_view === 'board'): ?>
<script src="assets/js/kanban.js" defer></script>
<?php endif; ?>
<script>
window.FoxDeskTicketListConfig = {
    currentView: <?php echo json_encode($ticket_view); ?>,
    ticketViewStorageKey: 'foxdesk_ticket_view',
    overdueIconHtml: <?php echo json_encode(get_icon('exclamation-circle', 'w-2.5 h-2.5 inline ml-0.5')); ?>,
    labels: {
        cancel: <?php echo json_encode(t('Cancel')); ?>,
        bulkSelect: <?php echo json_encode(t('Bulk select')); ?>,
        noTicketsFound: <?php echo json_encode(t('No tickets found')); ?>,
        enterToFilterList: <?php echo json_encode(t('Enter to filter list')); ?>,
        saved: <?php echo json_encode(t('Saved')); ?>,
        error: <?php echo json_encode(t('Error')); ?>,
        unassigned: <?php echo json_encode(t('Unassigned')); ?>,
        durationBounds: <?php echo json_encode(t('Duration must be between 1 and 1440 minutes.')); ?>,
        ticketCreated: <?php echo json_encode(t('Ticket created.')); ?>,
        save: <?php echo json_encode(t('Save')); ?>
    }
};
</script>
<script src="assets/js/ticket-list.js?v=<?php echo e($ticket_list_asset_version('assets/js/ticket-list.js')); ?>" defer></script>
<script src="assets/js/ticket-list-due-date.js?v=<?php echo e($ticket_list_asset_version('assets/js/ticket-list-due-date.js')); ?>" defer></script>
<script src="assets/js/ticket-list-time.js?v=<?php echo e($ticket_list_asset_version('assets/js/ticket-list-time.js')); ?>" defer></script>
<template id="tl-due-popover-tpl">
    <div class="tl-due-popover" role="dialog" aria-label="<?php echo e(t('Due date')); ?>">
        <input type="date" class="tl-due-popover__input">
        <div class="tl-due-popover__actions">
            <button type="button" class="tl-due-popover__btn tl-due-popover__clear"><?php echo e(t('Clear')); ?></button>
            <button type="button" class="tl-due-popover__btn tl-due-popover__btn--primary tl-due-popover__save"><?php echo e(t('Save')); ?></button>
        </div>
    </div>
</template>
<template id="inline-log-time-chips-tpl">
    <span class="ilt-chips" role="group" aria-label="<?php echo e(t('Log time')); ?>">
        <button type="button" class="ilt-chip" data-mins="5">+5</button>
        <button type="button" class="ilt-chip" data-mins="10">+10</button>
        <button type="button" class="ilt-chip" data-mins="15">+15</button>
        <button type="button" class="ilt-chip" data-mins="30">+30</button>
        <button type="button" class="ilt-chip" data-mins="60">+60</button>
        <button type="button" class="ilt-chip ilt-chip--custom" title="<?php echo e(t('Custom')); ?>">…</button>
    </span>
</template>
<template id="inline-log-time-custom-tpl">
    <div class="ilt-custom" role="dialog" aria-label="<?php echo e(t('Log time')); ?>">
        <input type="number" min="1" max="1440" step="1" class="ilt-duration"
            placeholder="<?php echo e(t('Minutes')); ?>" autofocus>
        <textarea class="ilt-note" rows="2" placeholder="<?php echo e(t('Note (optional)')); ?>"></textarea>
        <div class="ilt-custom__actions">
            <button type="button" class="ilt-cancel"><?php echo e(t('Cancel')); ?></button>
            <button type="button" class="ilt-save"><?php echo e(t('Save')); ?></button>
        </div>
    </div>
</template>
