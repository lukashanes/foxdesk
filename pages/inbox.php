<?php
/**
 * Inbox page.
 *
 * Triage surface for new, replied, and email-created tickets. This is separate
 * from the ticket registry and from analytics/dashboard.
 */

if (!is_admin() && !is_agent()) {
    redirect('work');
}

$page_title = t('Inbox');
$page = 'inbox';
$user = current_user();

$queue_key = trim((string) ($_GET['queue'] ?? 'triage'));
$queue_definitions = inbox_queue_definitions();
if (!isset($queue_definitions[$queue_key])) {
    $queue_key = 'triage';
}

$inbox_summary = inbox_summary($user, 12);
$active_queue = $inbox_summary[$queue_key] ?? ($inbox_summary['triage'] ?? reset($inbox_summary));
$active_items = $active_queue['items'] ?? [];

$inbox_queue_url = static function (string $key): string {
    return url('inbox', $key === 'triage' ? [] : ['queue' => $key]);
};

$inbox_ticket_list_url = static function (string $key): string {
    switch ($key) {
        case 'customer_replies':
            return url('tickets', ['sort' => 'last_updated']);
        case 'email_imports':
        case 'triage':
        default:
            return url('tickets', ['work_view' => 'open']);
    }
};

require_once BASE_PATH . '/includes/header.php';
?>

<style>
.inbox-shell {
    display: grid;
    grid-template-columns: minmax(230px, 300px) minmax(0, 1fr);
    gap: 1rem;
}
.inbox-list,
.inbox-panel {
    border: 1px solid var(--border-light);
    border-radius: 0.75rem;
    background: var(--surface-primary);
    overflow: hidden;
}
.inbox-list {
    align-self: start;
}
.inbox-queue {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.8rem 0.9rem;
    border-bottom: 1px solid var(--border-light);
    color: var(--text-secondary);
    text-decoration: none;
}
.inbox-queue:last-child {
    border-bottom: 0;
}
.inbox-queue:hover,
.inbox-queue.is-active {
    background: var(--surface-secondary);
    color: var(--text-primary);
}
.inbox-queue.is-active {
    box-shadow: inset 3px 0 0 var(--primary);
}
.inbox-queue__title {
    font-size: 0.9rem;
    font-weight: 800;
}
.inbox-queue__desc {
    margin-top: 0.125rem;
    color: var(--text-muted);
    font-size: 0.75rem;
}
.inbox-queue__count {
    margin-left: auto;
    min-width: 2rem;
    padding: 0.18rem 0.5rem;
    border-radius: 999px;
    background: var(--surface-tertiary, #eef2f7);
    color: var(--text-secondary);
    text-align: center;
    font-size: 0.75rem;
    font-weight: 800;
}
.inbox-panel__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem;
    border-bottom: 1px solid var(--border-light);
}
.inbox-panel__eyebrow {
    color: var(--text-muted);
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.inbox-panel__title {
    margin-top: 0.125rem;
    color: var(--text-primary);
    font-size: 1.25rem;
    font-weight: 850;
}
.inbox-ticket {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.75rem;
    padding: 0.9rem 1rem;
    border-bottom: 1px solid var(--border-light);
    color: inherit;
    text-decoration: none;
}
.inbox-ticket:last-child {
    border-bottom: 0;
}
.inbox-ticket:hover {
    background: var(--surface-secondary);
}
.inbox-ticket__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.45rem;
    margin-bottom: 0.3rem;
    color: var(--text-muted);
    font-size: 0.72rem;
}
.inbox-ticket__dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 999px;
}
.inbox-ticket__title {
    color: var(--text-primary);
    font-weight: 750;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.inbox-ticket__side {
    color: var(--text-muted);
    font-size: 0.75rem;
    text-align: right;
    white-space: nowrap;
}
.inbox-empty {
    padding: 2rem;
    text-align: center;
    color: var(--text-muted);
}
@media (max-width: 900px) {
    .inbox-shell {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-muted);"><?php echo e(t('Triage')); ?></div>
            <h1 class="text-2xl font-extrabold mt-1" style="color: var(--text-primary);"><?php echo e(t('Inbox')); ?></h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);"><?php echo e(t('Decide what should be assigned, started, merged, or closed.')); ?></p>
        </div>
        <a href="<?php echo e(url('new-ticket')); ?>" class="btn btn-primary btn-sm">
            <?php echo get_icon('plus', 'w-4 h-4 mr-1'); ?><?php echo e(t('New ticket')); ?>
        </a>
    </div>

    <div class="inbox-shell">
        <nav class="inbox-list" aria-label="<?php echo e(t('Inbox queues')); ?>">
            <?php foreach ($inbox_summary as $key => $queue): ?>
                <?php $definition = $queue['definition']; ?>
                <a href="<?php echo e($inbox_queue_url($key)); ?>" class="inbox-queue <?php echo $key === $queue_key ? 'is-active' : ''; ?>" <?php echo $key === $queue_key ? 'aria-current="page"' : ''; ?>>
                    <div>
                        <div class="inbox-queue__title"><?php echo e(t($definition['label'])); ?></div>
                        <div class="inbox-queue__desc"><?php echo e(t($definition['description'])); ?></div>
                    </div>
                    <span class="inbox-queue__count"><?php echo (int) ($queue['count'] ?? 0); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <section class="inbox-panel">
            <div class="inbox-panel__head">
                <div>
                    <div class="inbox-panel__eyebrow"><?php echo e(t('Current inbox')); ?></div>
                    <div class="inbox-panel__title"><?php echo e(t($active_queue['definition']['label'] ?? 'Triage')); ?></div>
                    <div class="text-sm mt-1" style="color: var(--text-secondary);"><?php echo e(t($active_queue['definition']['description'] ?? 'New or unassigned tickets that need a decision.')); ?></div>
                </div>
                <a href="<?php echo e($inbox_ticket_list_url($queue_key)); ?>" class="btn btn-secondary btn-sm"><?php echo e(t('View all')); ?></a>
            </div>

            <?php if (empty($active_items)): ?>
                <div class="inbox-empty">
                    <div class="font-bold" style="color: var(--text-primary);"><?php echo e(t('Inbox is clear')); ?></div>
                    <div class="text-sm mt-1"><?php echo e(t('No ticket needs triage in this queue.')); ?></div>
                </div>
            <?php else: ?>
                <div>
                    <?php foreach ($active_items as $ticket): ?>
                        <?php
                        $status_color = $ticket['status_color'] ?? '#64748b';
                        $org = trim((string) ($ticket['organization_name'] ?? ''));
                        $source = trim((string) ($ticket['source'] ?? ''));
                        ?>
                        <a href="<?php echo e(ticket_url($ticket)); ?>" class="inbox-ticket">
                            <div class="min-w-0">
                                <div class="inbox-ticket__meta">
                                    <span class="inbox-ticket__dot" style="background: <?php echo e($status_color); ?>"></span>
                                    <span><?php echo e(get_ticket_code((int) $ticket['id'])); ?></span>
                                    <span><?php echo e($ticket['status_name'] ?? ''); ?></span>
                                    <?php if ($org !== ''): ?><span><?php echo e($org); ?></span><?php endif; ?>
                                    <?php if ($source !== ''): ?><span><?php echo e($source); ?></span><?php endif; ?>
                                </div>
                                <div class="inbox-ticket__title"><?php echo e($ticket['title'] ?? ''); ?></div>
                            </div>
                            <div class="inbox-ticket__side">
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
