        <!-- Workflow Tab - Statuses, Priorities, Ticket Types -->
        <script src="assets/vendor/sortablejs/1.15.0/Sortable.min.js?v=<?php echo APP_VERSION; ?>"></script>

        <div class="workflow-grid">
            <?php foreach (admin_workflow_cards() as $workflow_card): ?>
                <?php render_admin_workflow_card($workflow_card); ?>
            <?php endforeach; ?>
        </div>
