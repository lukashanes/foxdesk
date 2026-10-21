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

// Client tabs: tickets, the client's work log (same read model as the Time page) and report history.
require_once BASE_PATH . '/includes/modules/work/time-ledger.php';
$client_tab_keys = is_admin() ? ['tickets', 'time', 'reports'] : ['tickets', 'time'];
$client_tab = in_array($_GET['tab'] ?? '', $client_tab_keys, true) ? (string) $_GET['tab'] : 'tickets';
$client_params = $_GET;
$client_link = static function (array $changes) use ($client_params, $organization_id): string {
    unset($client_params['page']);
    return url('client', array_merge($client_params, ['id' => $organization_id], $changes));
};
$ticket_paging = time_ledger_page($_GET, (int) ($counts[$ticket_view] ?? 0));
if ($client_tab === 'tickets') {
    $tickets = client_overview_recent_tickets(
        $organization_id,
        $ticket_view,
        $ticket_paging['size'] === 'all' ? 0 : (int) $ticket_paging['size'],
        $ticket_paging['offset']
    );
}
$client_ledger = null;
$ledger_request = [];
if ($client_tab === 'time') {
    $period = time_activity_period_from_request($_GET, false);
    $ledger_request = array_merge($_GET, ['client' => (string) $organization_id, 'time_scope' => is_admin() ? 'team' : 'mine']);
    unset($ledger_request['page'], $ledger_request['id'], $ledger_request['tab'], $ledger_request['work_view']);
    $client_ledger = time_ledger_model($user, $period, $ledger_request);
}
$client_reports = null;
if ($client_tab === 'reports') {
    require_once BASE_PATH . '/includes/modules/reports/report-history.php';
    $client_reports = report_history_model(array_merge($_GET, ['client' => $organization_id]));
}
$render_client_pager = static function (array $paging) use ($client_link): void {
    if ((int) $paging['count'] <= 10) {
        return;
    }
    ?><nav class="registry-pagination" aria-label="<?php echo e(t('Entries')); ?>"><span><?php echo (int) $paging['count']; ?> · <?php echo (int) $paging['page']; ?> / <?php echo (int) $paging['pages']; ?></span>
    <?php foreach (['10', '20', '50', 'all'] as $size): ?><a class="btn btn-secondary btn-sm" <?php echo $size === $paging['size'] ? 'aria-current="page"' : ''; ?> href="<?php echo e($client_link(['page_size' => $size, 'p' => 1])); ?>"><?php echo e($size === 'all' ? t('All') : $size); ?></a><?php endforeach; ?>
    <?php foreach ([-1 => t('Previous'), 1 => t('Next')] as $step => $label): $next = (int) $paging['page'] + $step; ?>
        <?php if ($next >= 1 && $next <= (int) $paging['pages']): ?><a class="btn btn-secondary btn-sm" href="<?php echo e($client_link(['p' => $next])); ?>"><?php echo e($label); ?></a><?php else: ?><button type="button" class="btn btn-secondary btn-sm" disabled><?php echo e($label); ?></button><?php endif; ?>
    <?php endforeach; ?></nav><?php
};

$page_title = $org['name'];
$page = 'client';

require_once BASE_PATH . '/includes/header.php';
?>

<div class="workflow-surface workflow-surface--client client-center"
    data-core-workflow-surface="client"
    data-app-contract-surface="client"
    data-app-contract-action="app-client-overview"
    data-client-id="<?php echo (int) $org['id']; ?>"
    data-client-view="<?php echo e($ticket_view); ?>">
    <div class="card client-hero">
        <div class="client-hero__summary min-w-0">
            <div class="client-hero__meta">
                <a href="<?php echo url('admin', ['section' => 'organizations']); ?>" class="client-back-link">
                    <?php echo get_icon('arrow-left', 'w-3.5 h-3.5 back-link-icon'); ?>
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
            <a href="<?php echo url('tickets', ['organization_id' => (int) $org['id']]); ?>" class="btn btn-secondary">
                <?php echo get_icon('file', 'w-4 h-4'); ?>
                <?php echo e(t('All tickets')); ?>
            </a>
            <a href="<?php echo url('admin', ['section' => 'reports', 'tab' => 'detailed', 'time_range' => 'this_month', 'organizations[]' => (int) $org['id'], 'show_money' => '1']); ?>" class="btn btn-primary">
                <?php echo get_icon('clock', 'w-4 h-4'); ?>
                <?php echo e(t('This month report')); ?>
            </a>
        </div>
    </div>

    <div class="client-stats">
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Open')); ?></div>
            <div class="client-stat__value" data-client-stat="open"><?php echo (int) $counts['open']; ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Waiting')); ?></div>
            <div class="client-stat__value" data-client-stat="waiting"><?php echo (int) $counts['waiting']; ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Done')); ?></div>
            <div class="client-stat__value" data-client-stat="done"><?php echo (int) $counts['done']; ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Time this month')); ?></div>
            <div class="client-stat__value" data-client-stat="time"><?php echo e(format_duration_minutes($time['minutes'])); ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Billable this month')); ?></div>
            <div class="client-stat__value" data-client-stat="billable"><?php echo e(format_money($time['billable_amount'])); ?></div>
        </div>
    </div>

    <div class="client-grid">
        <div class="card">
            <nav class="agenda-toolbar client-center-tabs" aria-label="<?php echo e(t('Client')); ?>">
            <?php foreach (['tickets' => t('Tickets'), 'time' => t('Work log'), 'reports' => t('Report history')] as $key => $label): if (!in_array($key, $client_tab_keys, true)) continue; ?>
                <a class="client-tab <?php echo $client_tab === $key ? 'is-active' : ''; ?>" href="<?php echo e($client_link(['tab' => $key, 'p' => 1])); ?>" <?php echo $client_tab === $key ? 'aria-current="page"' : ''; ?>><?php echo e($label); ?></a>
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
                    <?php foreach ($tickets as $ticket): ?>
                    <a class="client-ticket tr-hover"
                       data-client-ticket-row
                       data-ticket-id="<?php echo (int) $ticket['id']; ?>"
                       href="<?php echo url('ticket', ['id' => (int) $ticket['id']]); ?>">
                        <div class="min-w-0">
                            <div class="client-ticket__title" data-client-ticket-field="title"><?php echo e($ticket['title']); ?></div>
                            <div class="client-ticket__meta">
                                <span data-client-ticket-field="code"><?php echo get_ticket_code((int) $ticket['id']); ?></span>
                                <?php if (!empty($ticket['assignee_first_name']) || !empty($ticket['assignee_last_name'])): ?>
                                    <span data-client-ticket-field="assignee"><?php echo e(trim(($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? ''))); ?></span>
                                <?php else: ?>
                                    <span data-client-ticket-field="assignee"><?php echo e(t('Unassigned')); ?></span>
                                <?php endif; ?>
                                <span data-client-ticket-field="updated"><?php echo e(format_date($ticket['updated_at'] ?? $ticket['created_at'])); ?></span>
                            </div>
                        </div>
                        <span class="badge client-ticket-status"
                            data-client-ticket-field="status"
                            style="--client-status-color: <?php echo e($ticket['status_color'] ?? '#64748b'); ?>;">
                            <?php echo e($ticket['status_name'] ?? t('Status')); ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php $render_client_pager($ticket_paging); ?>
            <?php elseif ($client_tab === 'time'): ?>
                <?php include BASE_PATH . '/includes/components/client-time-ledger.php'; ?>
                <?php $render_client_pager($client_ledger['paging']); ?>
            <?php else: ?>
                <div class="ledger-scroll"><table class="ledger-table" data-client-report-history><thead><tr><th><?php echo e(t('Report')); ?></th><th><?php echo e(t('Period')); ?></th><th><?php echo e(t('Status')); ?></th></tr></thead><tbody>
                <?php foreach ($client_reports['rows'] as $report): ?><tr><td><a href="<?php echo e(url('admin', ['section' => 'report-builder', 'edit' => (int) $report['id']])); ?>"><bdi dir="auto"><?php echo e($report['title']); ?></bdi></a></td><td><?php echo e(format_date($report['date_from'], 'd.m.Y') . ' – ' . format_date($report['date_to'], 'd.m.Y')); ?></td><td><?php echo e($report['is_draft'] ? t('Draft') : t('Published')); ?></td></tr><?php endforeach; ?>
                <?php if (!$client_reports['rows']): ?><tr><td colspan="3"><?php echo e(t('No entries')); ?></td></tr><?php endif; ?>
                </tbody></table></div>
                <?php $render_client_pager($client_reports['paging']); ?>
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
                    <?php if (empty($contacts)): ?>
                        <p class="client-section-note" data-client-contact-empty><?php echo e(t('No contacts yet.')); ?></p>
                    <?php else: ?>
                        <?php foreach (array_slice($contacts, 0, 8) as $contact): ?>
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
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
