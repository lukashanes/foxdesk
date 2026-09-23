<?php
/**
 * Tickets list route.
 *
 * Request/query preparation and rendering live in focused ticket-list modules
 * so this route remains a small, auditable composition boundary.
 */
require BASE_PATH . '/includes/modules/tickets/ticket-list-page-controller.php';
require BASE_PATH . '/includes/components/ticket-list-page.php';
require BASE_PATH . '/includes/components/ticket-list-assets.php';
require_once BASE_PATH . '/includes/footer.php';
