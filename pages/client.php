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

$page_title = $org['name'];
$page = 'client';

require_once BASE_PATH . '/includes/header.php';
?>

<style>
    .client-center { display: grid; gap: 0.875rem; }
    .client-hero { display:grid; grid-template-columns:minmax(0, 1fr); gap:0.875rem; padding:1rem; }
    .client-hero__summary { min-width:0; max-width:min(100%, 60rem); }
    .client-hero__meta { display:flex; flex-wrap:wrap; gap:0.5rem; color:var(--text-muted); font-size:0.8125rem; }
    .client-title { display:-webkit-box; margin:0.3rem 0 0; color:var(--text-primary); font-size:var(--type-2xl); line-height:1.1; font-weight:750; letter-spacing:0; overflow:hidden; overflow-wrap:anywhere; text-wrap:balance; -webkit-box-orient:vertical; -webkit-line-clamp:2; }
    .client-actions { display:flex; flex-wrap:wrap; justify-content:flex-start; gap:0.5rem; min-width:0; padding-top:0.75rem; border-top:1px solid var(--border-light); }
    .client-stats { display:grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap:0.75rem; }
    .client-stat { padding:0.875rem; border:1px solid var(--border-light); border-radius:0.75rem; background:var(--surface-primary); }
    .client-stat__label { color:var(--text-muted); font-size:0.75rem; font-weight:700; text-transform:uppercase; }
    .client-stat__value { margin-top:0.25rem; color:var(--text-primary); font-size:1.25rem; font-weight:750; }
    .client-grid { display:grid; grid-template-columns:minmax(0, 1fr) 22rem; gap:0.875rem; align-items:start; }
    .client-tabs { display:flex; flex-wrap:wrap; gap:0.375rem; }
    .client-tab { display:inline-flex; align-items:center; gap:0.35rem; min-height:2rem; padding:0.35rem 0.65rem; border:1px solid var(--border-light); border-radius:0.625rem; color:var(--text-secondary); font-size:0.8125rem; font-weight:700; }
    .client-tab.is-active { background:var(--primary); border-color:var(--primary); color:#fff; }
    .client-ticket { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:0.75rem; align-items:center; padding:0.75rem 1rem; border-top:1px solid var(--border-light); }
    .client-contact { display:flex; align-items:center; justify-content:space-between; gap:0.75rem; padding:0.65rem 0; border-top:1px solid var(--border-light); }
    @media (max-width: 980px) {
        .client-grid { grid-template-columns:1fr; }
        .client-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .client-actions { justify-content:flex-start; }
    }
</style>

<div class="client-center">
    <div class="card client-hero">
        <div class="client-hero__summary min-w-0">
            <div class="client-hero__meta">
                <a href="<?php echo url('admin', ['section' => 'organizations']); ?>" class="inline-flex items-center gap-1 hover:underline" style="color:var(--text-muted);">
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
            <div class="client-stat__value"><?php echo (int) $counts['open']; ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Waiting')); ?></div>
            <div class="client-stat__value"><?php echo (int) $counts['waiting']; ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Done')); ?></div>
            <div class="client-stat__value"><?php echo (int) $counts['done']; ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Time this month')); ?></div>
            <div class="client-stat__value"><?php echo e(format_duration_minutes($time['minutes'])); ?></div>
        </div>
        <div class="client-stat">
            <div class="client-stat__label"><?php echo e(t('Billable this month')); ?></div>
            <div class="client-stat__value"><?php echo e(format_money($time['billable_amount'])); ?></div>
        </div>
    </div>

    <div class="client-grid">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="font-semibold" style="color:var(--text-primary);"><?php echo e(t('Client tickets')); ?></h2>
                    <p class="text-sm" style="color:var(--text-muted);"><?php echo e(t('Recent work connected to this client.')); ?></p>
                </div>
                <div class="client-tabs">
                    <?php foreach (['open', 'waiting', 'done', 'all'] as $view_key): ?>
                        <a class="client-tab <?php echo $ticket_view === $view_key ? 'is-active' : ''; ?>"
                           href="<?php echo url('client', ['id' => (int) $org['id'], 'work_view' => $view_key]); ?>">
                            <?php echo e(t(ticket_list_view_definitions(false)[$view_key]['label'] ?? ucfirst($view_key))); ?>
                            <span><?php echo (int) ($counts[$view_key] ?? 0); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (empty($tickets)): ?>
                <div class="p-6 text-center" style="color:var(--text-muted);">
                    <?php echo e(t('No tickets here')); ?>
                </div>
            <?php else: ?>
                <?php foreach ($tickets as $ticket): ?>
                    <a class="client-ticket tr-hover" href="<?php echo url('ticket', ['id' => (int) $ticket['id']]); ?>">
                        <div class="min-w-0">
                            <div class="font-semibold truncate" style="color:var(--text-primary);"><?php echo e($ticket['title']); ?></div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs" style="color:var(--text-muted);">
                                <span><?php echo get_ticket_code((int) $ticket['id']); ?></span>
                                <?php if (!empty($ticket['assignee_first_name']) || !empty($ticket['assignee_last_name'])): ?>
                                    <span><?php echo e(trim(($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? ''))); ?></span>
                                <?php else: ?>
                                    <span><?php echo e(t('Unassigned')); ?></span>
                                <?php endif; ?>
                                <span><?php echo e(format_date($ticket['updated_at'] ?? $ticket['created_at'])); ?></span>
                            </div>
                        </div>
                        <span class="badge px-2 py-0.5 text-xs"
                            style="background-color: <?php echo e($ticket['status_color'] ?? '#64748b'); ?>20; color: <?php echo e($ticket['status_color'] ?? '#64748b'); ?>">
                            <?php echo e($ticket['status_name'] ?? t('Status')); ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <aside class="space-y-3">
            <div class="card card-body">
                <h2 class="font-semibold mb-3" style="color:var(--text-primary);"><?php echo e(t('Client profile')); ?></h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt style="color:var(--text-muted);"><?php echo e(t('Billable rate')); ?></dt>
                        <dd class="font-semibold" style="color:var(--text-primary);"><?php echo e(format_money((float) ($org['billable_rate'] ?? 0))); ?></dd>
                    </div>
                    <?php if (!empty($org['contact_phone'])): ?>
                        <div class="flex justify-between gap-3">
                            <dt style="color:var(--text-muted);"><?php echo e(t('Phone')); ?></dt>
                            <dd style="color:var(--text-primary);"><?php echo e($org['contact_phone']); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($org['ico'])): ?>
                        <div class="flex justify-between gap-3">
                            <dt style="color:var(--text-muted);"><?php echo e(t('Company ID')); ?></dt>
                            <dd style="color:var(--text-primary);"><?php echo e($org['ico']); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>

            <div class="card card-body">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <h2 class="font-semibold" style="color:var(--text-primary);"><?php echo e(t('Contacts')); ?></h2>
                    <a href="<?php echo url('admin', ['section' => 'users']); ?>" class="text-sm font-semibold" style="color:var(--primary);"><?php echo e(t('Manage')); ?></a>
                </div>
                <?php if (empty($contacts)): ?>
                    <p class="text-sm" style="color:var(--text-muted);"><?php echo e(t('No contacts linked yet.')); ?></p>
                <?php else: ?>
                    <?php foreach (array_slice($contacts, 0, 8) as $contact): ?>
                        <div class="client-contact">
                            <div class="min-w-0">
                                <div class="font-semibold truncate" style="color:var(--text-primary);">
                                    <?php echo e(trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))); ?>
                                </div>
                                <div class="text-xs truncate" style="color:var(--text-muted);"><?php echo e($contact['email']); ?></div>
                            </div>
                            <span class="badge text-xs" style="background:var(--surface-secondary); color:var(--text-secondary);">
                                <?php echo e(ucfirst((string) $contact['role'])); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
