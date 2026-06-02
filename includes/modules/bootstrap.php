<?php
/**
 * FoxDesk module bootstrap.
 *
 * Keep product concepts in small modules. Page files should call services from
 * here instead of growing more business logic.
 */

require_once __DIR__ . '/tickets/ticket-status-groups.php';
require_once __DIR__ . '/tickets/ticket-events.php';
require_once __DIR__ . '/tickets/ticket-list-views.php';
require_once __DIR__ . '/tickets/ticket-detail-actions.php';
require_once __DIR__ . '/notifications/notification-policy.php';
require_once __DIR__ . '/email/email-renderer.php';
require_once __DIR__ . '/work/work-queues.php';
require_once __DIR__ . '/inbox/inbox-service.php';
require_once __DIR__ . '/search/global-search.php';
require_once __DIR__ . '/clients/client-overview.php';
require_once __DIR__ . '/reports/reporting-flow.php';
require_once __DIR__ . '/reports/billing-review.php';
require_once __DIR__ . '/app/app-shell.php';
require_once __DIR__ . '/app/app-feed.php';
