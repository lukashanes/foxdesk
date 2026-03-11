<?php
/**
 * Help Panel — Slide-out panel with keyboard shortcuts and how-to guides
 * Included from header.php, rendered after </aside>
 * Role-aware: shows different tabs based on user role
 */
$is_staff_user = is_admin() || is_agent();
$is_admin_user = is_admin();
?>

<!-- Help Panel Overlay -->
<div id="help-panel-overlay" class="help-panel-overlay" onclick="closeHelpPanel()"></div>

<!-- Help Panel -->
<div id="help-panel" class="help-panel" role="dialog" aria-modal="true" aria-label="<?php echo e(t('Help')); ?>">

    <!-- Header -->
    <div class="help-panel-header">
        <h2><?php echo e(t('Help')); ?></h2>
        <button onclick="closeHelpPanel()" aria-label="<?php echo e(t('Close')); ?>" class="help-panel-close">
            <?php echo get_icon('times', 'w-5 h-5'); ?>
        </button>
    </div>

    <!-- Tabs -->
    <div class="help-panel-tabs" role="tablist">
        <button class="help-panel-tab active" role="tab" aria-selected="true"
            data-tab="shortcuts" onclick="switchHelpTab('shortcuts')">
            <?php echo e(t('Shortcuts')); ?>
        </button>
        <button class="help-panel-tab" role="tab" aria-selected="false"
            data-tab="getting-started" onclick="switchHelpTab('getting-started')">
            <?php echo e(t('Getting Started')); ?>
        </button>
        <button class="help-panel-tab" role="tab" aria-selected="false"
            data-tab="tickets" onclick="switchHelpTab('tickets')">
            <?php echo e(t('Tickets')); ?>
        </button>
        <?php if ($is_staff_user): ?>
        <button class="help-panel-tab" role="tab" aria-selected="false"
            data-tab="staff" onclick="switchHelpTab('staff')">
            <?php echo e(t('For Staff')); ?>
        </button>
        <?php endif; ?>
        <?php if ($is_admin_user): ?>
        <button class="help-panel-tab" role="tab" aria-selected="false"
            data-tab="admin" onclick="switchHelpTab('admin')">
            <?php echo e(t('For Admins')); ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- Tab Content -->
    <div class="help-panel-body">

        <!-- ═══ SHORTCUTS TAB ═══ -->
        <div id="help-tab-shortcuts" class="help-tab-content active" role="tabpanel">
            <div class="help-shortcuts-grid">
                <kbd>Ctrl+K</kbd>
                <span><?php echo e(t('Command palette')); ?></span>

                <kbd>N</kbd>
                <span><?php echo e(t('New ticket')); ?></span>

                <kbd>/</kbd>
                <span><?php echo e(t('Focus search')); ?></span>

                <kbd>G → D</kbd>
                <span><?php echo e(t('Dashboard')); ?></span>

                <kbd>G → T</kbd>
                <span><?php echo e(t('Tickets')); ?></span>

                <?php if ($is_staff_user): ?>
                <kbd>G → R</kbd>
                <span><?php echo e(t('Reports')); ?></span>

                <kbd>G → O</kbd>
                <span><?php echo e(t('Organizations')); ?></span>

                <kbd>G → U</kbd>
                <span><?php echo e(t('Users')); ?></span>

                <kbd>G → S</kbd>
                <span><?php echo e(t('Settings')); ?></span>
                <?php endif; ?>

                <kbd>Esc</kbd>
                <span><?php echo e(t('Close modal')); ?></span>

                <kbd>?</kbd>
                <span><?php echo e(t('Show help')); ?></span>
            </div>

            <div class="help-section-divider"></div>

            <div class="help-item">
                <div class="help-item-desc">
                    <?php echo e(t('Hold Ctrl (or Cmd on Mac) and click a ticket row to open it in a new tab.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-desc" style="font-style: italic; color: var(--text-muted);">
                    <?php echo e(t('Shortcuts work only when not typing in a text field.')); ?>
                </div>
            </div>
        </div>

        <!-- ═══ GETTING STARTED TAB ═══ -->
        <div id="help-tab-getting-started" class="help-tab-content" role="tabpanel">

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('home', 'w-4 h-4'); ?>
                    <?php echo e(t('Navigation')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Use the sidebar to navigate between sections. Press Ctrl+K to open the command palette for quick access to any page or ticket.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('search', 'w-4 h-4'); ?>
                    <?php echo e(t('Search')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Use the search bar at the top or press / to quickly find tickets by title or ticket code.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('user', 'w-4 h-4'); ?>
                    <?php echo e(t('Your Profile')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Click your name at the bottom of the sidebar to access your profile, change your avatar, password, or language preference.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('moon', 'w-4 h-4'); ?>
                    <?php echo e(t('Dark Mode')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Toggle dark mode from the user menu at the bottom of the sidebar.')); ?>
                </div>
            </div>
        </div>

        <!-- ═══ TICKETS TAB ═══ -->
        <div id="help-tab-tickets" class="help-tab-content" role="tabpanel">

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('plus', 'w-4 h-4'); ?>
                    <?php echo e(t('Creating tickets')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Click "New Ticket" in the sidebar or press N to create a ticket. Fill in the title, description, and optionally set priority, type, and assignee.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('list', 'w-4 h-4'); ?>
                    <?php echo e(t('Viewing and filtering')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Use the filters at the top of the ticket list to narrow results by status, priority, assignee, or tags. Click column headers to sort.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('comment', 'w-4 h-4'); ?>
                    <?php echo e(t('Comments and attachments')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Add comments at the bottom of any ticket. You can attach files by clicking the attachment button or dragging files into the comment area.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('info-circle', 'w-4 h-4'); ?>
                    <?php echo e(t('Status and priority')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Each ticket has a status (e.g. New, In Progress, Done) and priority (e.g. Low, Medium, High). Change these from the ticket detail sidebar.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('external-link', 'w-4 h-4'); ?>
                    <?php echo e(t('Open in new tab')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Hold Ctrl (or Cmd on Mac) and click a ticket row to open it in a new tab.')); ?>
                </div>
            </div>
        </div>

        <?php if ($is_staff_user): ?>
        <!-- ═══ FOR STAFF TAB ═══ -->
        <div id="help-tab-staff" class="help-tab-content" role="tabpanel">

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('play', 'w-4 h-4'); ?>
                    <?php echo e(t('Quick Start')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Use Quick Start in the sidebar to create a ticket and automatically start a timer. Great for tracking time from the moment you start working.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('clock', 'w-4 h-4'); ?>
                    <?php echo e(t('Time tracking')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Start, pause, and stop timers directly from the ticket detail page. You can also log time manually with a summary. Active timers appear in the sidebar for quick access.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('chart-bar', 'w-4 h-4'); ?>
                    <?php echo e(t('Time reports')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('View logged time reports from the Time Report section in the sidebar. Filter by date range, user, or organization.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('user-plus', 'w-4 h-4'); ?>
                    <?php echo e(t('Ticket assignment')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Assign tickets to yourself or other agents from the ticket detail sidebar. You can also reassign tickets at any time.')); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_admin_user): ?>
        <!-- ═══ FOR ADMINS TAB ═══ -->
        <div id="help-tab-admin" class="help-tab-content" role="tabpanel">

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('users', 'w-4 h-4'); ?>
                    <?php echo e(t('Managing users')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Add, edit, and deactivate users from the Users section. Assign roles: Admin (full access), Agent (staff), or User (customer).')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('building', 'w-4 h-4'); ?>
                    <?php echo e(t('Organizations')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Manage companies and link users to organizations for organized ticket tracking. Each organization can have multiple users.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('cog', 'w-4 h-4'); ?>
                    <?php echo e(t('System settings')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Configure your app name, logo, email settings, and more from Settings. Set up SMTP for email notifications.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('tags', 'w-4 h-4'); ?>
                    <?php echo e(t('Customization')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Customize ticket statuses, priorities, and types to match your workflow. Drag to reorder, set colors, and rename as needed.')); ?>
                </div>
            </div>

            <div class="help-item">
                <div class="help-item-title">
                    <?php echo get_icon('sync-alt', 'w-4 h-4'); ?>
                    <?php echo e(t('Recurring tasks')); ?>
                </div>
                <div class="help-item-desc">
                    <?php echo e(t('Set up recurring tasks to automatically create tickets on a schedule (daily, weekly, monthly). Useful for regular maintenance or check-in tasks.')); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
