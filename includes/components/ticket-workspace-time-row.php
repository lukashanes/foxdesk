<?php
// The caller supplies a permission-filtered entry. Never duplicate time rows.
$entry_running = empty($entry['ended_at']);
$entry_minutes = $entry_running ? max(0, (int) floor(calculate_timer_elapsed($entry) / 60)) : (int) $entry['duration_minutes'];
$entry_name = trim(($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? ''));
?>
<div class="ticket-worklog-row" id="time-entry-<?php echo (int) $entry['id']; ?>" data-time-entry-id="<?php echo (int) $entry['id']; ?>">
    <span class="ticket-worklog-range"><span aria-hidden="true"><?php echo get_icon('clock', 'ticket-worklog-clock'); ?></span><span><?php echo e(ticket_time_entry_display_range($entry)); ?></span></span>
    <span class="ticket-worklog-person"><bdi dir="auto"><?php echo e($entry_name); ?></bdi></span>
    <span class="ticket-worklog-summary" title="<?php echo e($entry['summary'] ?? ''); ?>"><?php if (trim($entry['summary'] ?? '') !== ''): ?><bdi dir="auto"><?php echo e($entry['summary']); ?></bdi><?php endif; ?></span>
    <strong class="ticket-worklog-duration"><?php echo e(format_duration_minutes($entry_minutes)); ?></strong>
    <?php if (empty($ticket_time_read_only) && can_manage_time_entry($entry, $user) && !$entry_running): ?>
        <button type="button" class="ticket-inline-icon-button" data-workspace-time-edit="<?php echo (int) $entry['id']; ?>" title="<?php echo e(t('Edit time')); ?>" aria-label="<?php echo e(t('Edit time') . ' · ' . ticket_time_entry_display_range($entry)); ?>"><?php echo get_icon('pencil', 'w-3.5 h-3.5'); ?></button>
    <?php elseif ($entry_running): ?><span class="ticket-worklog-state"><?php echo e(t(empty($entry['paused_at']) ? 'Running' : 'Paused')); ?></span><?php endif; ?>
</div>
