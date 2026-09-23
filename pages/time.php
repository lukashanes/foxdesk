<?php
$user = current_user();
if (!in_array($user['role'] ?? '', ['admin', 'agent'], true)) { http_response_code(403); exit(t('Forbidden')); }
require_once BASE_PATH . '/includes/modules/work/time-ledger.php';
$page = 'time';
$page_title = t('Time');
$period = time_activity_period_from_request($_GET);
$scope = time_activity_view_scope_from_request($_GET, is_admin());
$request = $_GET;
$request['time_scope'] = $scope['key'];
$ledger = time_ledger_model($user, $period, $request);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="foxdesk-time.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map('t', ['Date', 'Ticket', 'Client', 'Agent', 'Summary', 'Time (min)', 'Billable']), ',', '"', '');
    // Export in bounded batches, with the same predicate as the visible aggregate.
    for ($offset = 0; $offset < (int) $ledger['totals']['count']; $offset += 500) {
        $rows = db_fetch_all($ledger['select'] . $ledger['from'] . $ledger['joins'] . $ledger['where'] .
            ' ORDER BY tte.started_at DESC, tte.id DESC LIMIT 500 OFFSET ' . $offset, $ledger['params']);
        foreach ($rows as $row) fputcsv($out, array_map('time_ledger_csv_value', [ticket_time_entry_display_date($row),
            get_ticket_code($row['ticket_id']), $row['client_name'], trim($row['first_name'] . ' ' . $row['last_name']),
            $row['summary'], $row['duration_minutes'], $row['is_billable'] ? t('Yes') : t('No')]), ',', '"', '');
    }
    fclose($out); exit;
}
$link = static function (array $changes) use ($request): string { return url('time', array_merge(array_diff_key($request, ['page' => true, 'export' => true]), $changes)); };
require BASE_PATH . '/includes/header.php';
?>
<div class="time-agenda" data-time-agenda>
<header class="agenda-heading"><h1><?php echo e(t('Time')); ?></h1><button class="btn btn-primary btn-sm" type="button" data-time-add><?php echo get_icon('plus', 'w-4 h-4'); ?><?php echo e(t('Log time')); ?></button></header>
<form method="get" class="fd-card agenda-toolbar">
    <input type="hidden" name="page" value="time">
    <label><?php echo e(t('Time period')); ?><select name="period" class="form-select"><?php foreach (time_activity_period_options() as $key => $label): ?><option value="<?php echo e($key); ?>" <?php echo $period['period'] === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
    <?php if (is_admin()): ?><label><?php echo e(t('View')); ?><select class="form-select" name="time_scope"><option value="mine" <?php echo $scope['key'] === 'mine' ? 'selected' : ''; ?>><?php echo e(t('My time')); ?></option><option value="team" <?php echo $scope['key'] === 'team' ? 'selected' : ''; ?>><?php echo e(t('Team time')); ?></option></select></label><?php endif; ?>
    <label><?php echo e(t('Client')); ?><select class="form-select" name="client"><option value=""><?php echo e(t('All')); ?></option><?php foreach ($ledger['clients'] as $client): ?><option value="<?php echo (int) $client['id']; ?>" <?php echo isset($_GET['client']) && (string) $_GET['client'] === (string) $client['id'] ? 'selected' : ''; ?>><?php echo e($client['name'] ?: t('-- No organization --')); ?></option><?php endforeach; ?></select></label>
    <label><?php echo e(t('Billable')); ?><select name="billable" class="form-select"><?php foreach (['' => t('All'), '1' => t('Yes'), '0' => t('No')] as $key => $label): ?><option value="<?php echo e((string) $key); ?>" <?php echo (string) ($request['billable'] ?? '') === (string) $key ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
    <label><?php echo e(t('Search')); ?><input type="search" name="search" class="form-input" value="<?php echo e(is_scalar($request['search'] ?? '') ? ($request['search'] ?? '') : ''); ?>"></label>
    <?php if ($ledger['agents']): ?><label><?php echo e(t('Agent')); ?><select class="form-select" name="agent"><option value=""><?php echo e(t('All')); ?></option><?php foreach ($ledger['agents'] as $agent): ?><option value="<?php echo (int) $agent['id']; ?>" <?php echo (string) ($request['agent'] ?? '') === (string) $agent['id'] ? 'selected' : ''; ?>><?php echo e(trim($agent['first_name'] . ' ' . $agent['last_name'])); ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <details <?php echo $period['period'] === 'custom' ? 'open' : ''; ?>><summary><?php echo e(t('Custom range')); ?></summary><label><?php echo e(t('From date')); ?><input type="date" name="from_date" class="form-input" value="<?php echo e($period['from_date']); ?>"></label><label><?php echo e(t('To date')); ?><input type="date" name="to_date" class="form-input" value="<?php echo e($period['to_date']); ?>"></label></details>
    <button class="btn btn-secondary btn-sm"><?php echo e(t('Apply')); ?></button>
</form>
<section class="fd-card ledger-metrics" aria-label="<?php echo e(t('Summary')); ?>">
<?php foreach (['minutes' => t('Saved'), 'billable' => t('Billable time'), 'count' => t('Entries'), 'clients' => t('Clients')] as $key => $label): ?><div><span><?php echo e($label); ?></span><strong><?php echo in_array($key, ['minutes', 'billable'], true) ? e(format_duration_minutes((int) $ledger['totals'][$key])) : (int) $ledger['totals'][$key]; ?></strong></div><?php endforeach; ?>
</section>
<section class="fd-card ledger-card">
<header class="agenda-toolbar"><h2><?php echo e(t('Work log')); ?></h2><a class="btn btn-secondary btn-sm" href="<?php echo e($link(['export' => 'csv'])); ?>">CSV</a></header>
<div class="ledger-scroll" role="region" tabindex="0" aria-label="<?php echo e(t('Work log')); ?>"><table class="ledger-table"><thead><tr><?php foreach (['Date', 'Ticket', 'Client', 'Agent', 'Billable', 'Time', 'Actions'] as $label): ?><th><?php echo e(t($label)); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($ledger['rows'] as $row): $exact = ticket_time_entry_precision($row) === 'exact'; ?>
<tr><td><?php echo e(format_date($exact ? $row['started_at'] : ($row['worked_on'] ?: $row['started_at']), 'd.m.Y')); ?><small><?php echo $exact ? e(date('H:i', strtotime($row['started_at'])) . ' – ' . date('H:i', strtotime($row['ended_at']))) : '—'; ?></small></td>
<td><a href="<?php echo e(url('ticket', ['t' => $row['ticket_hash']]) . '#time-entry-' . (int) $row['id']); ?>"><bdi dir="auto"><?php echo e($row['summary'] ?: $row['ticket_title']); ?></bdi><small><?php echo e(get_ticket_code($row['ticket_id'])); ?> · <bdi dir="auto"><?php echo e($row['ticket_title']); ?></bdi></small></a></td>
<td><bdi dir="auto"><?php echo e($row['client_name'] ?: '—'); ?></bdi></td><td><bdi dir="auto"><?php echo e(trim($row['first_name'] . ' ' . $row['last_name'])); ?></bdi></td><td><?php echo e($row['is_billable'] ? t('Yes') : t('No')); ?></td><td class="ledger-number"><?php echo e(format_duration_minutes((int) $row['duration_minutes'])); ?></td>
<td><?php if (can_manage_time_entry($row, $user)): ?><button type="button" class="btn btn-secondary btn-sm" data-time-edit="<?php echo (int) $row['id']; ?>"><?php echo e(t('Edit')); ?></button><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$ledger['rows']): ?><tr><td colspan="7"><?php echo e(t('No entries')); ?></td></tr><?php endif; ?>
</tbody></table></div>
<nav class="registry-pagination" aria-label="<?php echo e(t('Work log')); ?>"><span><?php echo (int) $ledger['paging']['page']; ?> / <?php echo (int) $ledger['paging']['pages']; ?></span>
<?php foreach (['10','20','50','all'] as $size): ?><a class="btn btn-secondary btn-sm" <?php echo $size === $ledger['paging']['size'] ? 'aria-current="page"' : ''; ?> href="<?php echo e($link(['page_size' => $size, 'p' => 1])); ?>"><?php echo e($size === 'all' ? t('All') : $size); ?></a><?php endforeach; ?>
<?php foreach ([-1 => t('Previous'), 1 => t('Next')] as $step => $label): $next = $ledger['paging']['page'] + $step; ?><a class="btn btn-secondary btn-sm" <?php echo $next < 1 || $next > $ledger['paging']['pages'] ? 'aria-disabled="true" tabindex="-1"' : ''; ?> href="<?php echo e($link(['p' => max(1,min($ledger['paging']['pages'],$next))])); ?>"><?php echo e($label); ?></a><?php endforeach; ?></nav>
</section>
<dialog class="ledger-dialog" data-time-dialog><form data-time-editor>
<header class="agenda-heading"><h2 data-time-title data-add="<?php echo e(t('Log time')); ?>" data-edit="<?php echo e(t('Edit')); ?>"><?php echo e(t('Log time')); ?></h2><button type="button" class="btn btn-secondary btn-sm" data-time-close><?php echo e(t('Close')); ?></button></header>
<div data-time-ticket-picker><label><?php echo e(t('Search')); ?><input type="search" class="form-input" data-time-ticket-search></label><div class="ledger-ticket-results" data-time-ticket-results></div><input type="hidden" name="ticket_id"></div>
<p data-time-ticket-caption></p><input type="hidden" name="entry_id">
<label><?php echo e(t('Date')); ?><input type="date" name="worked_on" class="form-input" required value="<?php echo date('Y-m-d'); ?>"></label>
<label><?php echo e(t('Start time')); ?><input type="time" name="start_time" class="form-input"></label>
<label><?php echo e(t('Time (min)')); ?><input type="number" name="duration_minutes" class="form-input" required min="1" max="1440"></label>
<label><?php echo e(t('Summary')); ?><textarea name="summary" class="form-input" rows="2"></textarea></label>
<label><input type="checkbox" name="is_billable" checked> <?php echo e(t('Billable')); ?></label>
<p role="alert" data-time-error hidden></p><button type="submit" class="btn btn-primary"><?php echo e(t('Save changes')); ?></button>
</form></dialog>
<script type="application/json" data-time-rows><?php echo json_encode(array_map(static fn($row) => array_intersect_key($row, array_flip(['id','ticket_id','ticket_title','started_at','ended_at','worked_on','time_precision','duration_minutes','summary','is_billable'])), $ledger['rows']), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
</div>
<script defer src="assets/js/time-ledger.js?v=<?php echo (int) filemtime(BASE_PATH . '/assets/js/time-ledger.js'); ?>"></script>
<?php require BASE_PATH . '/includes/footer.php'; ?>
