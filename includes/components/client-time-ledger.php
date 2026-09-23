<?php /** The same saved-entry read model as the Time agenda, locked to this client. */ ?>
<form method="get" class="agenda-toolbar">
    <input type="hidden" name="page" value="client"><input type="hidden" name="id" value="<?php echo $organization_id; ?>"><input type="hidden" name="tab" value="time">
    <label><?php echo e(t('Time period')); ?><select class="form-select" name="period"><?php foreach (time_activity_period_options() as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo $period['period']===$key?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
    <label><?php echo e(t('From date')); ?><input type="date" class="form-input" name="from_date" value="<?php echo e($period['from_date']); ?>"></label>
    <label><?php echo e(t('To date')); ?><input type="date" class="form-input" name="to_date" value="<?php echo e($period['to_date']); ?>"></label>
    <button class="btn btn-secondary btn-sm"><?php echo e(t('Apply')); ?></button>
    <a class="btn btn-secondary btn-sm" href="<?php echo e(url('time', $ledger_request)); ?>"><?php echo e(t('Log time')); ?></a>
</form>
<div class="ledger-scroll"><table class="ledger-table"><thead><tr><?php foreach (['Ticket','Agent','Date','Time'] as $label): ?><th><?php echo e(t($label)); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($client_ledger['rows'] as $entry): ?><tr>
    <td><a href="<?php echo e(url('ticket', ['t'=>$entry['ticket_hash']]) . '#time-entry-' . (int) $entry['id']); ?>"><bdi dir="auto"><?php echo e($entry['summary'] ?: $entry['ticket_title']); ?></bdi><small><?php echo e(get_ticket_code($entry['ticket_id'])); ?></small></a></td>
    <td><bdi dir="auto"><?php echo e(trim($entry['first_name'].' '.$entry['last_name'])); ?></bdi></td><td><?php echo e(ticket_time_entry_display_date($entry)); ?></td><td class="ledger-number"><?php echo e(format_duration_minutes((int)$entry['duration_minutes'])); ?></td>
</tr><?php endforeach; ?>
<?php if (!$client_ledger['rows']): ?><tr><td colspan="4"><?php echo e(t('No entries')); ?></td></tr><?php endif; ?>
</tbody><tfoot><tr><td colspan="3"><?php echo e(t('Total')); ?></td><td class="ledger-number"><?php echo e(format_duration_minutes((int)$client_ledger['totals']['minutes'])); ?></td></tr></tfoot></table></div>
