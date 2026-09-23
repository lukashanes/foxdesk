<?php
/** Staff activity uses exactly the selected ticket queue's tenant and visibility predicate. */
function work_recent_updates(string $queue, array $user, int $page = 1): array
{
    if (empty($user['id']) || !in_array($user['role'] ?? '', ['admin', 'agent'], true)) return ['rows' => [], 'count' => 0, 'page' => 1, 'pages' => 1];
    $params = [];
    $where = build_ticket_where_clause(work_queue_filters($queue, $user), $params);
    $from = ' FROM activity_log a JOIN tickets t ON t.id = a.ticket_id';
    $count = (int) (db_fetch_one('SELECT COUNT(*) AS count' . $from . $where, $params)['count'] ?? 0);
    $pages = max(1, (int) ceil($count / 8));
    $page = max(1, min($pages, $page));
    $rows = db_fetch_all('SELECT a.id, a.ticket_id, a.action, a.details, a.created_at, t.hash, t.title,
        u.first_name, u.last_name' . $from . ' LEFT JOIN users u ON u.id = a.user_id' . $where .
        ' ORDER BY a.created_at DESC, a.id DESC LIMIT 8 OFFSET ' . (($page - 1) * 8), $params);
    return compact('rows', 'count', 'page', 'pages');
}

function work_render_recent_updates(array $model): void
{
    echo '<div class="work-update-list">';
    if (!$model['rows']) echo '<p class="work-context-empty">' . e(t('No activity')) . '</p>';
    foreach ($model['rows'] as $entry) {
        $actor = trim(($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? '')) ?: t('System');
        $label = match ($entry['action']) {
            'commented', 'comment_edited', 'comment_deleted', 'comment_restored' => t('Comments'),
            'time_manual', 'time_updated', 'time_deleted', 'time_restored' => t('Work log'),
            'time_started', 'time_stopped', 'time_paused', 'time_resumed', 'time_discarded' => t('Time tracking'),
            default => t('Update'),
        };
        ?>
        <a class="work-update-row" href="<?php echo e(url('ticket', ['t' => $entry['hash']])); ?>">
            <span class="work-update-row__who"><bdi dir="auto"><?php echo e($actor); ?></bdi><time datetime="<?php echo e($entry['created_at']); ?>"><?php echo e(format_date($entry['created_at'])); ?></time></span>
            <span><strong><?php echo e(get_ticket_code($entry['ticket_id'])); ?></strong> · <bdi dir="auto"><?php echo e($entry['title']); ?></bdi></span>
            <small><strong><?php echo e($label); ?></strong> · <bdi dir="auto"><?php echo e($entry['details']); ?></bdi></small>
        </a>
        <?php
    }
    echo '</div>';
}
