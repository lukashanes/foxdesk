<?php
$page_header_title = t('New ticket');
$page_header_subtitle = '';
$page_header_breadcrumbs = [
    ['label' => t('Tickets'), 'url' => url('tickets')],
    ['label' => t('New ticket')]
];
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="w-full">
    <?php if ($error): ?>
        <div class="alert alert-error mb-4">
            <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card card-body" id="new-ticket-form" autocomplete="off"
        data-fresh-ticket="<?php echo $is_postback ? '0' : '1'; ?>">
        <?php echo csrf_field(); ?>
        <div class="space-y-4">
            <!-- Title -->
            <div>
                <label for="ticket-title-input" class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Subject')); ?> *</label>
                <input type="text" name="title" value="<?php echo e($_POST['title'] ?? ''); ?>" class="form-input"
                    required aria-required="true" autofocus id="ticket-title-input">
            </div>

            <!-- Description with Rich Text Editor -->
            <div>
                <label id="description-label" class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Description')); ?></label>
                <div class="editor-wrapper" role="textbox" aria-labelledby="description-label" aria-multiline="true">
                    <div id="description-editor"></div>
                </div>
                <input type="hidden" name="description" id="description-input" value="<?php echo e($_POST['description'] ?? ''); ?>">
            </div>


            <!-- File Upload + Company row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Attachments')); ?></label>
                    <div id="upload-zone" class="fd-rounded-card p-2 flex items-center gap-2 cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors border-theme-light">
                        <input type="file" name="attachments[]" id="file-input" multiple class="hidden"
                            accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                        <?php echo get_icon('cloud-upload-alt', 'text-lg flex-shrink-0'); ?>
                        <div class="flex-1 text-left min-w-0">
                            <p class="text-xs text-theme-secondary">
                                <span class="text-blue-500 font-medium"><?php echo e(t('Click')); ?></span>
                                <?php echo e(t('or drag files')); ?>
                            </p>
                        </div>
                    </div>
                    <p class="text-xs mt-1 text-theme-muted">
                        <?php echo e(t('Max {size}MB. Types: JPG, PNG, GIF, PDF, DOC, XLS, TXT, ZIP', ['size' => get_max_upload_size_mb()])); ?>
                    </p>
                    <?php if (get_request_upload_limit() > 0): ?>
                    <p class="text-xs mt-0.5 text-theme-muted">
                        <?php echo e(t('Total upload per request is limited to {size}.', ['size' => format_file_size(get_request_upload_limit())])); ?>
                    </p>
                    <?php endif; ?>
                    <div id="file-upload-errors" class="ticket-upload-error mt-2 hidden fd-rounded-card border px-3 py-2 text-xs"
                        aria-live="polite"></div>
                    <!-- File preview -->
                    <div id="file-preview" class="mt-1.5 space-y-1 hidden"></div>
                </div>

                <?php if (!empty($organizations)): ?>
                <div>
                    <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Company')); ?></label>
                    <select name="organization_id" class="form-select" autocomplete="off" data-reset-on-fresh-ticket="1">
                        <option value=""><?php echo e(t('-- No organization --')); ?></option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo (int) $org['id']; ?>" <?php echo $default_organization_id === (int) $org['id'] ? 'selected' : ''; ?>>
                                <?php echo e($org['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <!-- Status (admin/agent only) — visible immediately -->
            <?php if (is_admin() || is_agent()): ?>
            <div>
                <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Status')); ?></label>
                <input type="hidden" name="status_id" id="status_id" value="<?php echo (int) ($statuses[0]['id'] ?? 0); ?>">
                <div class="flex flex-wrap gap-1.5 items-center" id="status-selector">
                    <?php foreach ($statuses as $i => $status): ?>
                        <button type="button" class="option-pill <?php echo $i === 0 ? 'selected' : ''; ?>"
                            data-value="<?php echo (int) $status['id']; ?>" data-group="status"
                            data-input-id="status_id"
                            data-pill-color="<?php echo e($status['color'] ?? '#6b7280'); ?>">
                            <span><?php echo e($status['name']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Core ticket properties are visible without expanding advanced options. -->
            <section class="new-ticket-properties" aria-labelledby="new-ticket-properties-title">
                <h2 id="new-ticket-properties-title" class="text-sm font-semibold text-theme-primary"><?php echo e(t('Ticket properties')); ?></h2>
                <div class="pt-2 space-y-4">
                    <!-- Priority, Ticket Type, Due Date, Assign To, On Behalf Of -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        <!-- Priority -->
                        <div>
                            <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Priority')); ?></label>
                            <input type="hidden" name="priority_id" id="priority_id"
                                value="<?php echo $default_priority_id; ?>">
                            <div class="flex flex-wrap gap-1.5 items-center" id="priority-selector">
                                <?php foreach ($priorities as $priority):
                                    $is_selected = ($default_priority_id == $priority['id']);
                                    ?>
                                    <button type="button" class="option-pill <?php echo $is_selected ? 'selected' : ''; ?>"
                                        data-value="<?php echo $priority['id']; ?>" data-group="priority"
                                        data-input-id="priority_id"
                                        data-pill-color="<?php echo e($priority['color']); ?>">
                                        <span class="pill-icon"><?php echo get_icon($priority['icon'] ?? 'flag', 'w-3.5 h-3.5'); ?></span>
                                        <span><?php echo e($priority['name']); ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Ticket Type -->
                        <div>
                            <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Ticket type')); ?></label>
                            <input type="hidden" name="type" id="type" value="<?php echo e($default_type_slug); ?>">
                            <div class="flex flex-wrap gap-1.5 items-center" id="type-selector">
                                <?php foreach ($ticket_types as $tt):
                                    $is_selected = ($default_type_slug === $tt['slug']);
                                    ?>
                                    <button type="button" class="option-pill <?php echo $is_selected ? 'selected' : ''; ?>"
                                        data-value="<?php echo e($tt['slug']); ?>" data-group="type"
                                        data-input-id="type"
                                        data-pill-color="<?php echo e($tt['color']); ?>">
                                        <span class="pill-icon"><?php echo get_icon($tt['icon'], 'w-3.5 h-3.5'); ?></span>
                                        <span><?php echo e($tt['name']); ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Due Date -->
                        <div>
                            <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Due date')); ?></label>
                            <input type="datetime-local" name="due_date" value="<?php echo e($_POST['due_date'] ?? ''); ?>"
                                class="form-input">
                        </div>

                        <!-- Assign To (admin/agent only) -->
                        <?php if (is_admin() || is_agent()): ?>
                        <div>
                            <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Assign to')); ?></label>
                            <select name="assignee_id" class="form-select" autocomplete="off" data-reset-on-fresh-ticket="1">
                                <option value=""><?php echo e(t('-- Unassigned --')); ?></option>
                                <?php foreach ($staff_users as $su): ?>
                                    <option value="<?php echo (int) $su['id']; ?>" <?php echo ($default_assignee_id !== null && (int) ($su['id'] ?? 0) === $default_assignee_id) ? 'selected' : ''; ?>>
                                        <?php echo e($su['first_name'] . ' ' . $su['last_name']); ?>
                                        <?php if ((int) $su['id'] === $user['id']): ?>(<?php echo e(t('me')); ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- On Behalf Of (admin/agent only) -->
                        <div>
                            <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('On behalf of')); ?></label>
                            <select name="on_behalf_of" class="form-select">
                                <option value=""><?php echo e(t('-- Myself --')); ?></option>
                                <?php foreach ($all_users_list as $au): ?>
                                    <?php if ((int) $au['id'] === $user['id']) continue; ?>
                                    <option value="<?php echo (int) $au['id']; ?>">
                                        <?php echo e($au['first_name'] . ' ' . $au['last_name']); ?>
                                        (<?php echo e($au['email']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <!-- Tags (next to On Behalf Of) -->
                        <?php if ($tags_supported): ?>
                        <div>
                            <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Tags')); ?></label>
                            <input type="hidden" name="tags" id="nt-tags-value" value="<?php echo e($_POST['tags'] ?? ''); ?>">
                            <div class="chip-select" id="cs-tags">
                                <div class="chip-select__wrap" id="cs-tags-wrap">
                                    <div class="chip-select__chips" id="cs-tags-chips"></div>
                                    <input type="text" class="chip-select__input" id="cs-tags-input"
                                           placeholder="<?php echo e(t('Type to add tags...')); ?>" autocomplete="off">
                                </div>
                                <div class="chip-select__dropdown hidden" id="cs-tags-dropdown"></div>
                                <div id="cs-tags-hidden"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </section>
        </div>

        <?php if (is_agent() && function_exists('ticket_time_table_exists') && ticket_time_table_exists()): ?>
        <!-- Manual Time Entry (hidden by default) -->
        <div id="nt-manual-entry-row" class="hidden mt-3 pt-3 border-t border-theme-light">
            <div class="space-y-3">
                <div>
                    <label class="form-label-sm mb-1"><?php echo e(t('Time (min)')); ?></label>
                    <input
                        type="number"
                        name="manual_duration_minutes"
                        id="nt-manual-duration-minutes"
                        min="1"
                        max="1440"
                        step="1"
                        placeholder="15"
                        value="<?php echo e($_POST['manual_duration_minutes'] ?? ''); ?>"
                        class="form-input text-sm h-9 max-w-xs">
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" class="nt-manual-duration-chip btn btn-ghost px-2 py-1 text-xs" data-minutes="5">+5</button>
                        <button type="button" class="nt-manual-duration-chip btn btn-ghost px-2 py-1 text-xs" data-minutes="10">+10</button>
                        <button type="button" class="nt-manual-duration-chip btn btn-ghost px-2 py-1 text-xs" data-minutes="15">+15</button>
                        <button type="button" class="nt-manual-duration-chip btn btn-ghost px-2 py-1 text-xs" data-minutes="30">+30</button>
                        <button type="button" class="nt-manual-duration-chip btn btn-ghost px-2 py-1 text-xs" data-minutes="60">+60</button>
                    </div>
                    <p class="mt-2 text-xs text-theme-muted">
                        <?php echo e(t('Leave Start and End empty to log quick minutes ending now. If both are filled, the exact range is used instead.')); ?>
                    </p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                    <div>
                        <label class="form-label-sm mb-1"><?php echo e(t('Date')); ?></label>
                        <input type="date" name="manual_date" value="<?php echo e($_POST['manual_date'] ?? date('Y-m-d')); ?>" class="form-input text-sm h-9">
                    </div>
                    <div>
                        <label class="form-label-sm mb-1"><?php echo e(t('Start')); ?></label>
                        <input type="time" name="manual_start_time" value="<?php echo e($_POST['manual_start_time'] ?? ''); ?>" class="form-input text-sm h-9">
                    </div>
                    <div>
                        <label class="form-label-sm mb-1"><?php echo e(t('End')); ?></label>
                        <input type="time" name="manual_end_time" value="<?php echo e($_POST['manual_end_time'] ?? ''); ?>" class="form-input text-sm h-9">
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Buttons - aligned right with consistent height -->
        <div class="mt-4 pt-3 border-t flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <?php if (is_agent() && function_exists('ticket_time_table_exists') && ticket_time_table_exists()): ?>
                    <?php $auto_timer = isset($_GET['auto_timer']) && $_GET['auto_timer'] === '1'; ?>
                    <input type="hidden" name="timer_elapsed_seconds" id="timer_elapsed_seconds" value="0">
                    <div id="new-ticket-timer" class="flex items-center gap-2" data-auto-start="<?php echo $auto_timer ? '1' : '0'; ?>">
                        <button type="button" id="nt-timer-btn"
                            class="btn btn-success px-3 py-1.5 text-sm inline-flex items-center gap-1.5 transition-colors"
                            data-state="stopped"
                            title="<?php echo e(t('Start timer')); ?>">
                            <span class="nt-timer-icon"><?php echo get_icon('play', 'w-4 h-4'); ?></span>
                            <span class="nt-timer-text"><?php echo e(t('Start timer')); ?></span>
                        </button>
                        <button type="button" id="nt-timer-discard"
                            class="hidden btn btn-ghost px-2 py-1.5 hover:text-red-500 transition-colors text-theme-muted"
                            title="<?php echo e(t('Discard timer')); ?>">
                            <?php echo get_icon('trash', 'w-4 h-4'); ?>
                        </button>
                    </div>
                    <!-- Manual time entry toggle -->
                    <button type="button" id="nt-manual-toggle" class="btn btn-ghost px-2 py-1.5 text-theme-muted" title="<?php echo e(t('Manual entry')); ?>">
                        <?php echo get_icon('pen', 'w-4 h-4'); ?>
                    </button>

                <?php endif; ?>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?php echo url(function_exists('foxdesk_authenticated_home_page') ? foxdesk_authenticated_home_page() : 'dashboard'); ?>" class="btn btn-ghost flex items-center">
                    <?php echo e(t('Cancel')); ?>
                </a>
                <button type="submit" class="btn btn-primary flex items-center">
                    <?php echo e(t('Save')); ?>
                </button>
            </div>
        </div>
    </form>

</div>

<link rel="stylesheet" href="assets/css/new-ticket.css?v=<?php echo defined('APP_VERSION') ? APP_VERSION : '1'; ?>">

<?php require BASE_PATH . '/includes/components/new-ticket-assets.php'; ?>
