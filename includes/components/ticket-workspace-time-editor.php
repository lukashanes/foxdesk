<?php
$workspace_editable_entries = array_values(array_filter($time_entries, static fn($row) => can_manage_time_entry($row, $user) && !empty($row['ended_at'])));
?>
<dialog class="ledger-dialog ticket-workspace-editor" data-workspace-time-dialog data-title-add="<?php echo e(t('Log time')); ?>" data-title-edit="<?php echo e(t('Edit time')); ?>" aria-labelledby="workspace-time-title">
    <form data-workspace-time-form>
        <header><h2 id="workspace-time-title"><?php echo e(t('Log time')); ?></h2><button type="button" class="btn btn-ghost btn-sm" data-workspace-time-close><?php echo e(t('Close')); ?></button></header>
        <input type="hidden" name="time_entry_id"><input type="hidden" name="ticket_id" value="<?php echo (int) $ticket_id; ?>">
        <label><?php echo e(t('ticket.workspace.conversation')); ?><select name="comment_id" class="form-select"><option value="0"><?php echo e(t('ticket.workspace.standalone')); ?></option>
            <?php foreach ($comments as $item): ?><option value="<?php echo (int) $item['id']; ?>"><?php echo e(trim($item['first_name'] . ' ' . $item['last_name']) . ' · ' . mb_substr(trim(strip_tags($item['content'])), 0, 90)); ?></option><?php endforeach; ?>
        </select></label>
        <label><?php echo e(t('Summary')); ?><textarea name="summary" rows="2" class="form-input"></textarea></label>
        <div class="ticket-workspace-editor__fields">
            <label><?php echo e(t('Date')); ?><input name="worked_on" type="date" class="form-input" required value="<?php echo date('Y-m-d'); ?>"></label>
            <label><?php echo e(t('Start time')); ?><input name="start_time" type="time" class="form-input"></label>
            <label><?php echo e(t('Time (min)')); ?><input name="duration_minutes" type="number" min="1" max="1440" value="30" class="form-input" required></label>
        </div>
        <footer><label class="ticket-workspace-editor__billable"><input type="checkbox" name="is_billable" checked><?php echo e(t('Billable')); ?></label><button type="button" class="btn btn-ghost text-red-600" data-workspace-time-delete hidden><?php echo e(t('Delete')); ?></button><button type="submit" class="btn btn-primary"><?php echo e(t('Save')); ?></button></footer>
        <p role="alert" data-workspace-time-error hidden></p>
    </form>
    <script type="application/json" data-workspace-time-entries><?php echo json_encode(array_map(static fn($row) => array_intersect_key($row, array_flip(['id','ticket_id','comment_id','started_at','worked_on','time_precision','duration_minutes','summary','is_billable'])), $workspace_editable_entries), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
</dialog>
<script defer src="assets/js/ticket-workspace.js?v=<?php echo (int) filemtime(BASE_PATH . '/assets/js/ticket-workspace.js'); ?>"></script>
