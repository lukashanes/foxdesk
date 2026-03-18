<?php
/**
 * Ticket Management Functions - Facade
 *
 * This file serves as a facade that includes all ticket-related modules.
 * It maintains backward compatibility by requiring all the modular files.
 *
 * Modules:
 * - ticket-query-functions.php   : Query builders and fulltext search
 * - ticket-crud-functions.php    : CRUD operations and comments
 * - ticket-time-functions.php    : Time tracking entries
 * - ticket-access-functions.php  : Ticket access control
 * - ticket-share-functions.php   : Public share links for tickets/reports
 * - upload-functions.php         : File upload and attachments
 */

// Include all ticket-related modules
require_once __DIR__ . '/ticket-query-functions.php';
require_once __DIR__ . '/ticket-crud-functions.php';
require_once __DIR__ . '/ticket-time-functions.php';
require_once __DIR__ . '/ticket-access-functions.php';
require_once __DIR__ . '/ticket-share-functions.php';
require_once __DIR__ . '/upload-functions.php';


