<?php
/**
 * API Endpoints - Main Entry Point
 *
 * This file serves as the entry point for all API requests.
 * It uses a modular router to dispatch to appropriate handlers.
 *
 * Modules:
 * - api/router.php          : Main dispatcher
 * - api/reorder-handler.php : Status/priority reordering
 * - api/upload-handler.php  : File uploads
 * - api/ticket-handler.php  : Ticket operations
 * - api/user-handler.php    : User search
 * - api/smtp-handler.php    : SMTP testing
 */

// Include the modular router
require_once __DIR__ . '/api/router.php';

// Get the action from request
$action = $_GET['action'] ?? '';

// Route the request
route_api_request($action);


