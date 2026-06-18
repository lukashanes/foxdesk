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

<?php
workspace_render_queue_page([
    'title' => 'Inbox',
    'summary' => $inbox_summary,
    'active_key' => $queue_key,
    'active_queue' => $active_queue,
    'items' => $active_items,
    'queue_url' => $inbox_queue_url,
    'view_all_url' => $inbox_ticket_list_url($queue_key),
    'primary_action' => workspace_surface_action(url('new-ticket'), 'New ticket'),
    'row_options' => ['show_source' => true],
    'contract_surface' => 'inbox',
    'contract_collection' => 'inbox',
    'contract_limit' => 12,
]);
?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
