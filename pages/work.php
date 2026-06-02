<?php
/**
 * Work page.
 *
 * Action-first view for daily support work. Dashboard keeps analytics; this page
 * shows the queues that need attention now.
 */

$page_title = t('Work');
$page = 'work';
$user = current_user();
$is_staff = is_admin() || is_agent();

$queue_key = trim((string) ($_GET['queue'] ?? 'mine'));
$queue_definitions = work_queue_definitions();
if (!isset($queue_definitions[$queue_key])) {
    $queue_key = 'mine';
}
if (($queue_definitions[$queue_key]['scope'] ?? '') === 'team' && !$is_staff) {
    $queue_key = 'mine';
}

$queue_summary = work_queue_summary($user, 6);
$active_queue = $queue_summary[$queue_key] ?? ($queue_summary['mine'] ?? reset($queue_summary));
$active_items = $active_queue['items'] ?? [];

$work_queue_url = static function (string $key): string {
    return url('work', $key === 'mine' ? [] : ['queue' => $key]);
};

$work_tickets_url = static function (string $key) use ($user): string {
    switch ($key) {
        case 'mine':
            return url('tickets', ['assigned_to' => (int) ($user['id'] ?? 0)]);
        case 'overdue':
            return url('tickets', ['due_date' => 'overdue']);
        case 'waiting':
            return url('tickets', ['work_view' => 'waiting']);
        case 'done_today':
            return url('tickets', ['work_view' => 'done', 'sort' => 'last_updated']);
        case 'unassigned':
        default:
            return url('tickets');
    }
};

require_once BASE_PATH . '/includes/header.php';
?>

<style>
.work-shell {
    display: grid;
    grid-template-columns: minmax(240px, 320px) minmax(0, 1fr);
    gap: 1rem;
}
.work-queue-list,
.work-panel {
    border: 1px solid var(--border-light);
    border-radius: 0.75rem;
    background: var(--surface-primary);
    overflow: hidden;
}
.work-queue-list {
    align-self: start;
}
.work-queue-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
    padding: 0.75rem 0.875rem;
    color: var(--text-secondary);
    text-decoration: none;
    border-bottom: 1px solid var(--border-light);
}
.work-queue-link:last-child {
    border-bottom: 0;
}
.work-queue-link:hover,
.work-queue-link.is-active {
    background: var(--surface-secondary);
    color: var(--text-primary);
}
.work-queue-link.is-active {
    box-shadow: inset 3px 0 0 var(--primary);
}
.work-queue-title {
    font-weight: 800;
    font-size: 0.9rem;
}
.work-queue-desc {
    margin-top: 0.125rem;
    font-size: 0.75rem;
    color: var(--text-muted);
}
.work-queue-count {
    margin-left: auto;
    min-width: 2rem;
    padding: 0.2rem 0.5rem;
    border-radius: 999px;
    background: var(--surface-tertiary, #eef2f7);
    color: var(--text-secondary);
    text-align: center;
    font-weight: 800;
    font-size: 0.75rem;
}
.work-panel__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem;
    border-bottom: 1px solid var(--border-light);
}
.work-panel__eyebrow {
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted);
}
.work-panel__title {
    margin-top: 0.15rem;
    font-size: 1.25rem;
    font-weight: 850;
    color: var(--text-primary);
}
.work-ticket-list {
    display: grid;
}
.work-ticket {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--border-light);
    color: inherit;
    text-decoration: none;
}
.work-ticket:last-child {
    border-bottom: 0;
}
.work-ticket:hover {
    background: var(--surface-secondary);
}
.work-ticket__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.45rem;
    margin-bottom: 0.3rem;
    color: var(--text-muted);
    font-size: 0.72rem;
}
.work-ticket__dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 999px;
}
.work-ticket__title {
    color: var(--text-primary);
    font-weight: 750;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.work-ticket__side {
    text-align: right;
    color: var(--text-muted);
    font-size: 0.75rem;
    white-space: nowrap;
}
.work-empty {
    padding: 2rem;
    text-align: center;
    color: var(--text-muted);
}
@media (max-width: 900px) {
    .work-shell {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-muted);"><?php echo e(t('Work queues')); ?></div>
            <h1 class="text-2xl font-extrabold mt-1" style="color: var(--text-primary);"><?php echo e(t('Work')); ?></h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);"><?php echo e(t('Start with the queue that needs attention now.')); ?></p>
        </div>
        <a href="<?php echo e(url('new-ticket')); ?>" class="btn btn-primary btn-sm">
            <?php echo get_icon('plus', 'w-4 h-4 mr-1'); ?><?php echo e(t('New ticket')); ?>
        </a>
    </div>

    <div class="work-shell">
        <nav class="work-queue-list" aria-label="<?php echo e(t('Work queues')); ?>">
            <?php foreach ($queue_summary as $key => $queue): ?>
                <?php $definition = $queue['definition']; ?>
                <a href="<?php echo e($work_queue_url($key)); ?>" class="work-queue-link <?php echo $key === $queue_key ? 'is-active' : ''; ?>" <?php echo $key === $queue_key ? 'aria-current="page"' : ''; ?>>
                    <div>
                        <div class="work-queue-title"><?php echo e(t($definition['label'])); ?></div>
                        <div class="work-queue-desc"><?php echo e(t($definition['description'])); ?></div>
                    </div>
                    <span class="work-queue-count"><?php echo (int) ($queue['count'] ?? 0); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <section class="work-panel">
            <div class="work-panel__head">
                <div>
                    <div class="work-panel__eyebrow"><?php echo e(t('Current queue')); ?></div>
                    <div class="work-panel__title"><?php echo e(t($active_queue['definition']['label'] ?? 'My work')); ?></div>
                    <div class="text-sm mt-1" style="color: var(--text-secondary);"><?php echo e(t($active_queue['definition']['description'] ?? 'Tickets assigned to the current user.')); ?></div>
                </div>
                <a href="<?php echo e($work_tickets_url($queue_key)); ?>" class="btn btn-secondary btn-sm"><?php echo e(t('View all')); ?></a>
            </div>

            <?php if (empty($active_items)): ?>
                <div class="work-empty">
                    <div class="font-bold" style="color: var(--text-primary);"><?php echo e(t('No tickets here')); ?></div>
                    <div class="text-sm mt-1"><?php echo e(t('This queue is clear.')); ?></div>
                </div>
            <?php else: ?>
                <div class="work-ticket-list">
                    <?php foreach ($active_items as $ticket): ?>
                        <?php
                        $status_color = $ticket['status_color'] ?? '#64748b';
                        $assignee = trim((string) (($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? '')));
                        $org = trim((string) ($ticket['organization_name'] ?? ''));
                        ?>
                        <a href="<?php echo e(ticket_url($ticket)); ?>" class="work-ticket">
                            <div class="min-w-0">
                                <div class="work-ticket__meta">
                                    <span class="work-ticket__dot" style="background: <?php echo e($status_color); ?>"></span>
                                    <span><?php echo e(get_ticket_code((int) $ticket['id'])); ?></span>
                                    <span><?php echo e($ticket['status_name'] ?? ''); ?></span>
                                    <?php if ($org !== ''): ?><span><?php echo e($org); ?></span><?php endif; ?>
                                </div>
                                <div class="work-ticket__title"><?php echo e($ticket['title'] ?? ''); ?></div>
                            </div>
                            <div class="work-ticket__side">
                                <?php if ($assignee !== ''): ?>
                                    <div><?php echo e($assignee); ?></div>
                                <?php endif; ?>
                                <div><?php echo e(format_date($ticket['updated_at'] ?? $ticket['created_at'] ?? '', 'd.m.Y')); ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
