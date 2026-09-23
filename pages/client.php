<?php
/**
 * Client detail center.
 */

$user = current_user();
if (!is_admin() && !is_agent()) {
    redirect('work');
}

$organization_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$ticket_view = ticket_list_view_normalize($_GET['work_view'] ?? 'open', true);
$overview = client_overview($organization_id, $ticket_view);

if (!$overview) {
    flash(t('Client not found.'), 'error');
    redirect('admin', ['section' => 'organizations']);
}

$org = $overview['organization'];
$counts = $overview['counts'];
$tickets = $overview['tickets'];
$contacts = $overview['contacts'];
$time = $overview['time'];
require_once BASE_PATH . '/includes/modules/work/time-ledger.php';
$client_tab = in_array($_GET['tab'] ?? '', is_admin() ? ['tickets','time','reports'] : ['tickets','time'], true) ? $_GET['tab'] : 'tickets';
$client_params = $_GET;
$client_link = static function (array $changes) use ($client_params, $organization_id): string {
    unset($client_params['page']); return url('client', array_merge($client_params, ['id' => $organization_id], $changes));
};
$ticket_paging = time_ledger_page($_GET, (int) ($counts[$ticket_view] ?? 0));
$tickets = client_overview_recent_tickets($organization_id, $ticket_view, $ticket_paging['size'] === 'all' ? 0 : (int) $ticket_paging['size'], $ticket_paging['offset']);
$contact_query = trim(is_scalar($_GET['contact_search'] ?? '') ? (string) ($_GET['contact_search'] ?? '') : '');
$contacts = array_values(array_filter($contacts, static fn($contact) => $contact_query === '' || mb_stripos($contact['first_name'] . ' ' . $contact['last_name'] . ' ' . $contact['email'], $contact_query) !== false));
$contact_pages = max(1, (int) ceil(count($contacts) / 5));
$contact_page = max(1, min($contact_pages, (int) ($_GET['contacts_page'] ?? 1)));
$period = time_activity_period_from_request($_GET);
$ledger_request = array_merge($_GET, ['client' => (string) $organization_id, 'time_scope' => is_admin() ? 'team' : 'mine']);
unset($ledger_request['page'], $ledger_request['id'], $ledger_request['tab'], $ledger_request['work_view'], $ledger_request['contacts_page'], $ledger_request['contact_search']);
$client_ledger = $client_tab === 'time' ? time_ledger_model($user, $period, $ledger_request) : null;
$client_reports = [];
$report_paging = time_ledger_page($_GET, 0);
if ($client_tab === 'reports' && is_admin()) {
    $report_params = [$organization_id];
    $report_where = ' WHERE rt.organization_id = ?';
    if (function_exists('report_template_column_exists') && report_template_column_exists('is_archived')) $report_where .= ' AND rt.is_archived = 0';
    $report_count = (int) (db_fetch_one('SELECT COUNT(*) AS count FROM report_templates rt' . $report_where, $report_params)['count'] ?? 0);
    $report_paging = time_ledger_page($_GET, $report_count);
    $client_reports = db_fetch_all('SELECT rt.id, rt.title, rt.date_from, rt.date_to, rt.is_draft FROM report_templates rt' . $report_where .
        ' ORDER BY rt.created_at DESC, rt.id DESC' . ($report_paging['size'] === 'all' ? '' : ' LIMIT ' . (int) $report_paging['size'] . ' OFFSET ' . $report_paging['offset']), $report_params);
}
$render_client_pager = static function ($paging) use ($client_link): void {
    ?><nav class="registry-pagination" aria-label="<?php echo e(t('Entries')); ?>"><span><?php echo $paging['count']; ?> · <?php echo $paging['page']; ?> / <?php echo $paging['pages']; ?></span>
    <?php foreach (['10','20','50','all'] as $size): ?><a class="btn btn-secondary btn-sm" <?php echo $size === $paging['size'] ? 'aria-current="page"' : ''; ?> href="<?php echo e($client_link(['page_size' => $size, 'p' => 1])); ?>"><?php echo e($size === 'all' ? t('All') : $size); ?></a><?php endforeach; ?>
    <?php foreach ([-1 => t('Previous'), 1 => t('Next')] as $step => $label): $next = $paging['page'] + $step; ?>
        <?php if ($next >= 1 && $next <= $paging['pages']): ?><a class="btn btn-secondary btn-sm" href="<?php echo e($client_link(['p' => $next])); ?>"><?php echo e($label); ?></a><?php else: ?><button class="btn btn-secondary btn-sm" disabled><?php echo e($label); ?></button><?php endif; ?>
    <?php endforeach; ?></nav><?php
};

$page_title = $org['name'];
$page = 'client';

require_once BASE_PATH . '/includes/header.php';
?>

<div class="workflow-surface workflow-surface--client client-center"
    data-core-workflow-surface="client"
    data-app-contract-surface="server-client"
    data-app-contract-action="app-client-overview"
    data-client-id="<?php echo (int) $org['id']; ?>"
    data-client-view="<?php echo e($ticket_view); ?>">
    <div class="card client-hero">
        <div class="client-hero__summary min-w-0">
            <div class="client-hero__meta">
                <a href="<?php echo url('admin', ['section' => 'organizations']); ?>" class="client-back-link">
                    <?php echo get_icon('arrow-left', 'w-3.5 h-3.5'); ?>
                    <?php echo e(t('Clients')); ?>
                </a>
                <span><?php echo !empty($org['is_active']) ? e(t('Active')) : e(t('Inactive')); ?></span>
                <?php if (!empty($org['contact_email'])): ?>
                    <span><?php echo e($org['contact_email']); ?></span>
                <?php endif; ?>
            </div>
            <h1 class="client-title" title="<?php echo e($org['name']); ?>"><?php echo e($org['name']); ?></h1>
        </div>
        <div class="client-actions">
            <a href="<?php echo url('tickets', ['organization' => (int) $org['id'], 'work_view' => 'all']); ?>" class="btn btn-secondary">
                <?php echo get_icon('file', 'w-4 h-4'); ?>
                <?php echo e(t('All tickets')); ?>
            </a>
            <a href="<?php echo url('admin', ['section' => 'reports', 'tab' => 'detailed', 'time_range' => 'this_month', 'organizations[]' => (int) $org['id'], 'show_money' => '1']); ?>" class="btn btn-primary">
                <?php echo get_icon('clock', 'w-4 h-4'); ?>
                <?php echo e(t('This month report')); ?>
            </a>
        </div>
    </div>

    <div class="client-stats client-stats--primary">
        <a class="client-stat" href="<?php echo e(url('time', ['client' => $organization_id, 'period' => 'today', 'time_scope' => is_admin() ? 'team' : 'mine'])); ?>"><div class="client-stat__label"><?php echo e(t('Today')); ?></div><div class="client-stat__value"><?php echo e(format_duration_minutes((int) $time['today'])); ?></div></a>
        <div class="client-stat"><div class="client-stat__label"><?php echo e(t('Time this month')); ?></div><div class="client-stat__value" data-client-stat="time"><?php echo e(format_duration_minutes($time['minutes'])); ?></div></div>
        <div class="client-stat"><div class="client-stat__label"><?php echo e(t('Open')); ?></div><div class="client-stat__value" data-client-stat="open"><?php echo (int) $counts['open']; ?></div></div>
        <div class="client-stat"><div class="client-stat__label"><?php echo e(t('Billable this month')); ?></div><div class="client-stat__value" data-client-stat="billable"><?php echo e(format_money($time['billable_amount'])); ?></div></div>
    </div>
    <div class="client-secondary-summary">
        <a href="<?php echo e(url('time', ['client' => $organization_id, 'period' => 'this_week', 'time_scope' => is_admin() ? 'team' : 'mine'])); ?>"><?php echo e(t('This week')); ?> <strong><?php echo e(format_duration_minutes((int) $time['week'])); ?></strong></a>
        <span><?php echo e(t('Waiting')); ?> <strong data-client-stat="waiting"><?php echo (int) $counts['waiting']; ?></strong></span>
        <span><?php echo e(t('Done')); ?> <strong data-client-stat="done"><?php echo (int) $counts['done']; ?></strong></span>
    </div>

    <div class="client-grid">
        <div class="card">
            <nav class="agenda-toolbar fd-segmented" aria-label="<?php echo e(t('Client')); ?>">
            <?php foreach (['tickets' => t('Tickets'), 'time' => t('Work log'), 'reports' => t('Report history')] as $key => $label): if ($key === 'reports' && !is_admin()) continue; ?>
                <a class="fd-segmented__item <?php echo $client_tab === $key ? 'is-active' : ''; ?>" href="<?php echo e($client_link(['tab' => $key, 'p' => 1])); ?>" <?php echo $client_tab === $key ? 'aria-current="page"' : ''; ?>><?php echo e($label); ?></a>
            <?php endforeach; ?>
            </nav>
            <?php if ($client_tab === 'tickets'): ?>
            <div class="card-header">
                <div>
                    <h2 class="client-section-title"><?php echo e(t('Client tickets')); ?></h2>
                </div>
                <div class="client-tabs">
                    <?php foreach (['open', 'waiting', 'done', 'all'] as $view_key): ?>
                        <a class="client-tab <?php echo $ticket_view === $view_key ? 'is-active' : ''; ?>"
                           data-client-view-key="<?php echo e($view_key); ?>"
                           href="<?php echo e($client_link(['work_view' => $view_key, 'p' => 1])); ?>">
                            <?php echo e(t(ticket_list_view_definitions(false)[$view_key]['label'] ?? ucfirst($view_key))); ?>
                            <span data-client-view-count><?php echo (int) ($counts[$view_key] ?? 0); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div data-client-ticket-list>
                <?php if (empty($tickets)): ?>
                    <div class="client-empty" data-client-empty>
                        <?php echo e(t('All clear')); ?>
                    </div>
                <?php else: ?>
                    <div class="ledger-scroll"><table class="ledger-table client-ticket-table">
                    <thead><tr><th><?php echo e(t('ID')); ?></th><th><?php echo e(t('Subject')); ?></th><th><?php echo e(t('Status')); ?></th><th><?php echo e(t('Updated')); ?></th><th class="ledger-number"><?php echo e(t('Time')); ?></th></tr></thead><tbody>
                    <?php foreach ($tickets as $ticket): ?>
                    <tr class="tr-hover"
                       data-client-ticket-row
                       data-ticket-id="<?php echo (int) $ticket['id']; ?>">
                        <td><a href="<?php echo url('ticket', ['id' => (int) $ticket['id']]); ?>" data-client-ticket-field="code"><?php echo get_ticket_code((int) $ticket['id']); ?></a></td>
                        <td class="min-w-0">
                            <a class="client-ticket__title" href="<?php echo url('ticket', ['id' => (int) $ticket['id']]); ?>" data-client-ticket-field="title"><bdi dir="auto"><?php echo e($ticket['title']); ?></bdi></a>
                            <div class="client-ticket__meta">
                                <?php if (!empty($ticket['assignee_first_name']) || !empty($ticket['assignee_last_name'])): ?>
                                    <span data-client-ticket-field="assignee"><?php echo e(trim(($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? ''))); ?></span>
                                <?php else: ?>
                                    <span data-client-ticket-field="assignee"><?php echo e(t('Unassigned')); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><span class="badge client-ticket-status"
                            data-client-ticket-field="status"
                            style="--client-status-color: <?php echo e($ticket['status_color'] ?? '#64748b'); ?>;">
                            <?php echo e($ticket['status_name'] ?? t('Status')); ?>
                        </span></td>
                        <td data-client-ticket-field="updated"><?php echo e(format_date($ticket['updated_at'] ?? $ticket['created_at'], 'd.m. H:i')); ?></td>
                        <td class="ledger-number"><strong><?php echo e(format_duration_minutes((int) ($ticket['saved_minutes'] ?? 0))); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </div>
            <?php $render_client_pager($ticket_paging); ?>
            <?php elseif ($client_tab === 'time'): ?>
                <?php include BASE_PATH . '/includes/components/client-time-ledger.php'; ?>
                <?php $render_client_pager($client_ledger['paging']); ?>
            <?php else: ?>
                <div class="ledger-scroll"><table class="ledger-table"><thead><tr><th><?php echo e(t('Report')); ?></th><th><?php echo e(t('Period')); ?></th><th><?php echo e(t('Status')); ?></th></tr></thead><tbody>
                <?php foreach ($client_reports as $report): ?><tr><td><a href="<?php echo e(url('admin', ['section' => 'report-builder', 'edit' => (int) $report['id']])); ?>"><bdi dir="auto"><?php echo e($report['title']); ?></bdi></a></td><td><?php echo e(format_date($report['date_from'], 'd.m.Y') . ' – ' . format_date($report['date_to'], 'd.m.Y')); ?></td><td><?php echo e($report['is_draft'] ? t('Draft') : t('Published')); ?></td></tr><?php endforeach; ?>
                <?php if (!$client_reports): ?><tr><td colspan="3"><?php echo e(t('No entries')); ?></td></tr><?php endif; ?>
                </tbody></table></div>
                <?php $render_client_pager($report_paging); ?>
            <?php endif; ?>
        </div>

        <aside class="space-y-3">
            <div class="card card-body">
                <h2 class="client-section-title client-section-title--spaced"><?php echo e(t('Client profile')); ?></h2>
                <dl class="client-profile-list">
                    <div class="client-profile-row">
                        <dt class="client-profile-term"><?php echo e(t('Billable rate')); ?></dt>
                        <dd class="client-profile-value client-profile-value--strong"><?php echo e(format_money((float) ($org['billable_rate'] ?? 0))); ?></dd>
                    </div>
                    <?php if (!empty($org['contact_phone'])): ?>
                        <div class="client-profile-row">
                            <dt class="client-profile-term"><?php echo e(t('Phone')); ?></dt>
                            <dd class="client-profile-value"><?php echo e($org['contact_phone']); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($org['ico'])): ?>
                        <div class="client-profile-row">
                            <dt class="client-profile-term"><?php echo e(t('Company ID')); ?></dt>
                            <dd class="client-profile-value"><?php echo e($org['ico']); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>

            <div class="card card-body">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <h2 class="client-section-title"><?php echo e(t('Contacts')); ?></h2>
                    <a href="<?php echo url('admin', ['section' => 'users']); ?>" class="client-manage-link"><?php echo e(t('Manage')); ?></a>
                </div>
                <div data-client-contact-list>
                    <form method="get" class="client-contact-search"><input type="hidden" name="page" value="client"><input type="hidden" name="id" value="<?php echo $organization_id; ?>"><input type="hidden" name="tab" value="<?php echo e($client_tab); ?>"><label class="sr-only" for="client-contact-search"><?php echo e(t('Search')); ?></label><input type="search" class="form-input" id="client-contact-search" name="contact_search" value="<?php echo e($contact_query); ?>"><button class="btn btn-secondary btn-sm"><?php echo e(t('Search')); ?></button></form>
                    <?php if (empty($contacts)): ?>
                        <p class="client-section-note" data-client-contact-empty><?php echo e(t('No contacts yet.')); ?></p>
                    <?php else: ?>
                        <?php foreach (array_slice($contacts, ($contact_page - 1) * 5, 5) as $contact): ?>
                        <div class="client-contact"
                            data-client-contact-row
                            data-contact-id="<?php echo (int) $contact['id']; ?>">
                            <div class="min-w-0">
                                <div class="client-contact__name" data-client-contact-field="name">
                                    <?php echo e(trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))); ?>
                                </div>
                                <div class="client-contact__email" data-client-contact-field="email"><?php echo e($contact['email']); ?></div>
                            </div>
                            <span class="badge client-contact__role" data-client-contact-field="role">
                                <?php echo e(ucfirst((string) $contact['role'])); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($contact_pages > 1): ?><nav class="registry-pagination" aria-label="<?php echo e(t('Contacts')); ?>"><span><?php echo $contact_page; ?> / <?php echo $contact_pages; ?></span><?php foreach ([-1 => t('Previous'), 1 => t('Next')] as $step => $label): $target=$contact_page+$step; ?><?php if ($target>=1 && $target<=$contact_pages): ?><a class="btn btn-secondary btn-sm" href="<?php echo e($client_link(['contacts_page'=>$target])); ?>"><?php echo e($label); ?></a><?php endif; ?><?php endforeach; ?></nav><?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
