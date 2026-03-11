<?php
/**
 * Client Reports Management
 * List, manage, and share professional time reports
 */

if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$current_user = current_user();
$page_title = t('Client Reports');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $action = $_POST['action'] ?? '';
    $report_id = (int) ($_POST['report_id'] ?? 0);

    if ($action === 'delete' && $report_id > 0) {
        if (delete_report_template($report_id)) {
            flash(t('Report deleted successfully.'), 'success');
        } else {
            flash(t('Failed to delete report.'), 'error');
        }
    } elseif ($action === 'archive' && $report_id > 0) {
        if (update_report_template($report_id, ['is_archived' => 1])) {
            flash(t('Report archived successfully.'), 'success');
        } else {
            flash(t('Failed to archive report.'), 'error');
        }
    } elseif ($action === 'publish' && $report_id > 0) {
        if (update_report_template($report_id, ['is_draft' => 0])) {
            $template = get_report_template($report_id);
            $share_token = create_report_template_share($report_id, $template['organization_id']);
            flash(t('Report published successfully!'), 'success');
        } else {
            flash(t('Failed to publish report.'), 'error');
        }
    } elseif ($action === 'set_expiration' && $report_id > 0) {
        $expires_at = trim($_POST['expires_at'] ?? '');
        $update_data = [];

        if ($expires_at === '') {
            // Remove expiration
            $update_data['expires_at'] = null;
        } else {
            // Set expiration
            $timestamp = strtotime($expires_at);
            if ($timestamp === false || $timestamp <= time()) {
                flash(t('Expiration must be in the future.'), 'error');
            } else {
                $update_data['expires_at'] = date('Y-m-d H:i:s', $timestamp);
            }
        }

        if (!empty($update_data)) {
            if (update_report_template($report_id, $update_data)) {
                flash($expires_at === '' ? t('Expiration removed.') : t('Expiration date set successfully.'), 'success');
            } else {
                flash(t('Failed to update expiration.'), 'error');
            }
        }
    }

    redirect('admin', ['section' => 'reports-list']);
}

// Get all reports
$reports_where = [];
if (function_exists('report_template_column_exists') && report_template_column_exists('is_archived')) {
    $reports_where[] = 'rt.is_archived = 0';
}

$reports = db_fetch_all("
    SELECT rt.*, o.name as organization_name,
           u.first_name, u.last_name
    FROM report_templates rt
    LEFT JOIN organizations o ON rt.organization_id = o.id
    LEFT JOIN users u ON rt.created_by_user_id = u.id
    " . (!empty($reports_where) ? 'WHERE ' . implode(' AND ', $reports_where) : '') . "
    ORDER BY rt.created_at DESC
");

include BASE_PATH . '/includes/header.php';
?>

<div class="p-4 lg:p-8">
    <!-- Page Header -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold" style="color: var(--text-primary);">
                    <?php echo get_icon('file', 'w-6 h-6 mr-3 inline-block'); ?><?php echo e(t('Client Reports')); ?>
                </h1>
                <p class="mt-1 text-sm" style="color: var(--text-muted);">
                    <?php echo e(t('Create and manage professional time tracking reports for clients')); ?>
                </p>
            </div>
            <a href="<?php echo url('admin', ['section' => 'report-builder']); ?>" class="btn btn-primary">
                <?php echo get_icon('plus', 'w-4 h-4 mr-2 inline-block'); ?><?php echo e(t('Create New Report')); ?>
            </a>
        </div>
    </div>

    <!-- Reports List -->
    <?php if (empty($reports)): ?>
        <div class="card card-body p-12 text-center">
            <div class="mb-4" style="color: var(--text-muted);"><?php echo get_icon('file', 'w-16 h-16 mx-auto'); ?></div>
            <h3 class="text-lg font-semibold mb-2" style="color: var(--text-primary);"><?php echo e(t('No Reports Yet')); ?>
            </h3>
            <p class="mb-2" style="color: var(--text-muted);">
                <?php echo e(t('Get started by creating your first client report')); ?>
            </p>
            <a href="<?php echo url('admin', ['section' => 'report-builder']); ?>" class="btn btn-primary">
                <?php echo get_icon('plus', 'w-4 h-4 mr-2 inline-block'); ?>     <?php echo e(t('Create First Report')); ?>
            </a>
        </div>
    <?php else: ?>
        <div class="card overflow-hidden">
            <table class="w-full">
                <thead style="background: var(--surface-secondary); border-color: var(--border-light);" class="border-b">
                    <tr>
                        <th class="px-6 py-3 text-left th-label">
                            <?php echo e(t('Report')); ?>
                        </th>
                        <th class="px-6 py-3 text-left th-label">
                            <?php echo e(t('Client')); ?>
                        </th>
                        <th class="px-6 py-3 text-left th-label">
                            <?php echo e(t('Period')); ?>
                        </th>
                        <th class="px-6 py-3 text-left th-label">
                            <?php echo e(t('Status')); ?>
                        </th>
                        <th class="px-6 py-3 text-left th-label">
                            <?php echo e(t('Created')); ?>
                        </th>
                        <th class="px-6 py-3 text-right th-label">
                            <?php echo e(t('Actions')); ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y" style="border-color: var(--border-light);">
                    <?php foreach ($reports as $report): ?>
                        <tr class="tr-hover">
                            <td class="px-6 py-4">
                                <div class="font-medium" style="color: var(--text-primary);"><?php echo e($report['title']); ?>
                                </div>
                                <div class="text-xs" style="color: var(--text-muted);">
                                    <?php echo get_icon('globe', 'w-3 h-3 mr-1 inline-block'); ?>
                                    <?php echo e(strtoupper($report['report_language'])); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                                <?php echo e($report['organization_name']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                                <?php echo date('M j', strtotime($report['date_from'])); ?> -
                                <?php echo date('M j, Y', strtotime($report['date_to'])); ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($report['is_draft']): ?>
                                    <span class="badge-inline bg-yellow-100 text-yellow-800">
                                        <?php echo get_icon('edit', 'w-3 h-3 mr-1 inline-block'); ?>             <?php echo e(t('Draft')); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge-inline bg-green-100 text-green-800">
                                        <?php echo get_icon('check', 'w-3 h-3 mr-1 inline-block'); ?>
                                        <?php echo e(t('Published')); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($report['expires_at'])): ?>
                                    <?php
                                    $expires_timestamp = strtotime($report['expires_at']);
                                    $is_expired = $expires_timestamp < time();
                                    ?>
                                    <div class="mt-1">
                                        <span
                                            class="badge-inline <?php echo $is_expired ? 'bg-red-100 text-red-800' : 'bg-orange-100 text-orange-800'; ?>">
                                            <?php echo get_icon('clock', 'w-3 h-3 mr-1 inline-block'); ?>
                                            <?php echo $is_expired ? e(t('Expired')) : e(t('Expires')) . ' ' . format_date($report['expires_at'], 'M j'); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm" style="color: var(--text-secondary);">
                                <?php echo date('M j, Y', strtotime($report['created_at'])); ?>
                                <div class="text-xs" style="color: var(--text-muted);">
                                    <?php echo e(t('by')); ?>
                                    <?php echo e($report['first_name'] . ' ' . $report['last_name']); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end space-x-2">
                                    <?php if (!$report['is_draft']): ?>
                                        <!-- View Public Report -->
                                        <a href="<?php echo url('report-public', ['token' => $report['uuid']]); ?>" target="_blank"
                                            class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                            <?php echo get_icon('external-link', 'w-3.5 h-3.5 mr-1 inline-block'); ?>
                                            <?php echo e(t('View')); ?>
                                        </a>

                                        <!-- Copy Share Link -->
                                        <button
                                            onclick="copyShareLink('<?php echo e(APP_URL . '/index.php?page=report-public&token=' . $report['uuid']); ?>', this)"
                                            class="text-green-600 hover:text-green-800 text-sm font-medium inline-flex items-center">
                                            <?php echo get_icon('link', 'w-3.5 h-3.5 mr-1 inline-block'); ?>
                                            <?php echo e(t('Share')); ?>
                                        </button>

                                        <!-- Download PDF -->
                                        <button
                                            onclick="downloadPDF('<?php echo APP_URL . '/index.php?page=report-public&token=' . $report['uuid']; ?>')"
                                            class="text-purple-600 hover:text-purple-800 text-sm font-medium inline-flex items-center">
                                            <?php echo get_icon('file-pdf', 'w-3.5 h-3.5 mr-1 inline-block'); ?>
                                            <?php echo e(t('PDF')); ?>
                                        </button>

                                        <!-- Set Expiration -->
                                        <?php
                                        $exp_val = '';
                                        if (!empty($report['expires_at']) && strpos($report['expires_at'], '0000-00-00') === false) {
                                            $ts = strtotime($report['expires_at']);
                                            if ($ts) {
                                                $exp_val = date('Y-m-d\TH:i', $ts);
                                            }
                                        }
                                        ?>
                                        <button
                                            onclick="openExpirationModal(<?php echo (int) $report['id']; ?>, '<?php echo $exp_val; ?>')"
                                            class="text-orange-600 hover:text-orange-800 text-sm font-medium inline-flex items-center">
                                            <?php echo get_icon('clock', 'w-3.5 h-3.5 mr-1 inline-block'); ?>
                                            <?php echo e(t('Expiration')); ?>
                                        </button>
                                    <?php else: ?>
                                        <!-- Publish Draft -->
                                        <form method="POST" class="inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="publish">
                                            <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                            <button type="submit" class="text-green-600 hover:text-green-800 text-sm font-medium">
                                                <?php echo get_icon('send', 'w-3.5 h-3.5 mr-1 inline-block'); ?>
                                                <?php echo e(t('Publish')); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Delete -->
                                    <form method="POST" class="inline"
                                        onsubmit="return confirm('<?php echo e(t('Are you sure you want to delete this report?')); ?>');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-medium">
                                            <?php echo get_icon('trash', 'w-3.5 h-3.5 mr-1 inline-block'); ?>
                                            <?php echo e(t('Delete')); ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Expiration Modal -->
<div id="expirationModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="rounded-xl shadow-xl max-w-md w-full mx-4 p-4" style="background: var(--surface-primary);">
        <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">
            <span
                class="text-orange-600 inline-block mr-2"><?php echo get_icon('clock', 'w-5 h-5'); ?></span><?php echo e(t('Set Report Expiration')); ?>
        </h3>
        <form method="POST" id="expirationForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="set_expiration">
            <input type="hidden" name="report_id" id="expiration_report_id">

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2" style="color: var(--text-secondary);">
                        <?php echo e(t('Expiration Date & Time')); ?>
                    </label>
                    <input type="datetime-local" name="expires_at" id="expires_at_input" class="form-input">
                    <p class="mt-1 text-xs" style="color: var(--text-muted);">
                        <?php echo e(t('Leave empty to remove expiration')); ?>
                    </p>
                </div>

                <div class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                    <span class="text-blue-500"><?php echo get_icon('info-circle', 'w-4 h-4'); ?></span>
                    <span><?php echo e(t('After expiration, the share link will no longer be accessible')); ?></span>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 mt-3">
                <button type="button" onclick="closeExpirationModal()" class="btn btn-secondary">
                    <?php echo e(t('Cancel')); ?>
                </button>
                <button type="submit" class="btn btn-primary px-4 py-2">
                    <?php echo get_icon('check', 'w-4 h-4 mr-2 inline-block'); ?><?php echo e(t('Save')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function copyShareLink(url, button) {
        navigator.clipboard.writeText(url).then(() => {
            const originalText = button.innerHTML;
            // SVG icon is PHP-rendered, safe static content
            button.innerHTML = '<?php echo get_icon('check', 'w-3.5 h-3.5 mr-1 inline-block'); ?><?php echo e(t('Copied!')); ?>';
            button.classList.remove('text-green-600', 'hover:text-green-800');
            button.classList.add('text-blue-600');
            setTimeout(() => {
                button.innerHTML = originalText;
                button.classList.remove('text-blue-600');
                button.classList.add('text-green-600', 'hover:text-green-800');
            }, 2000);
        }).catch(() => {
            alert('<?php echo e(t('Failed to copy link. Please copy manually.')); ?>');
        });
    }

    function downloadPDF(url) {
        // Open in new window with focus on print
        const printWindow = window.open(url, '_blank', 'width=1024,height=768');
        if (printWindow) {
            printWindow.onload = function () {
                // Wait for page to fully load, then trigger print
                setTimeout(() => {
                    printWindow.print();
                    // Optional: close window after printing
                    // printWindow.onafterprint = () => printWindow.close();
                }, 1500);
            };
        } else {
            alert('<?php echo e(t('Please allow popups to download PDF')); ?>');
        }
    }

    function openExpirationModal(reportId, currentExpiration) {
        document.getElementById('expiration_report_id').value = reportId;
        document.getElementById('expires_at_input').value = currentExpiration;
        const modal = document.getElementById('expirationModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeExpirationModal() {
        const modal = document.getElementById('expirationModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // Close modal on outside click
    document.getElementById('expirationModal')?.addEventListener('click', function (e) {
        if (e.target === this) {
            closeExpirationModal();
        }
    });
</script>

<?php include BASE_PATH . '/includes/footer.php';
