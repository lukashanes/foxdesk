<?php
/**
 * FoxDesk module bootstrap.
 *
 * Keep product concepts in small modules. Page files should call services from
 * here instead of growing more business logic.
 */

require_once __DIR__ . '/tickets/ticket-status-groups.php';
require_once __DIR__ . '/tickets/ticket-events.php';
require_once __DIR__ . '/tickets/ticket-bulk-actions.php';
require_once __DIR__ . '/tickets/ticket-list-views.php';
require_once __DIR__ . '/tickets/ticket-list-filters.php';
require_once __DIR__ . '/tickets/ticket-row-view-model.php';
require_once __DIR__ . '/tickets/ticket-detail-actions.php';
require_once __DIR__ . '/tickets/ticket-detail-read-model.php';
require_once __DIR__ . '/tickets/ticket-share-state.php';
require_once __DIR__ . '/tickets/ticket-detail-context.php';
require_once __DIR__ . '/notifications/notification-policy.php';
require_once __DIR__ . '/email/email-renderer.php';
require_once __DIR__ . '/work/work-queues.php';
require_once __DIR__ . '/work/time-activity-summary.php';
require_once __DIR__ . '/inbox/inbox-service.php';
require_once __DIR__ . '/search/global-search.php';
require_once __DIR__ . '/clients/client-overview.php';
require_once __DIR__ . '/reports/report-filters.php';
require_once __DIR__ . '/reports/reporting-flow.php';
require_once __DIR__ . '/reports/billing-review.php';
require_once __DIR__ . '/reports/report-totals.php';
require_once __DIR__ . '/reports/report-query.php';
require_once __DIR__ . '/reports/report-adjustments.php';
require_once __DIR__ . '/reports/report-export.php';
require_once __DIR__ . '/settings/settings-actions.php';
require_once __DIR__ . '/settings/settings-email.php';
require_once __DIR__ . '/settings/settings-updates.php';
require_once __DIR__ . '/settings/settings-security.php';
require_once __DIR__ . '/settings/settings-workflow.php';
require_once __DIR__ . '/settings/settings-view-model.php';
require_once __DIR__ . '/settings/settings-templates.php';
require_once __DIR__ . '/team/team-users.php';
require_once __DIR__ . '/app/dashboard-compat.php';
require_once __DIR__ . '/app/app-contract.php';
require_once __DIR__ . '/app/app-shell.php';
require_once __DIR__ . '/app/app-feed.php';
