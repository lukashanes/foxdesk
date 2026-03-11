<?php
/**
 * Ticket Query Helper Functions
 *
 * Functions for building ticket queries, search, and filtering.
 */

/**
 * Build a boolean fulltext query string.
 */
function build_ticket_fulltext_query($search)
{
    $clean = preg_replace('/[^\pL\pN\s]+/u', ' ', $search);
    $parts = preg_split('/\s+/', trim($clean));
    $tokens = [];
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (strlen($part) < 2) {
            continue;
        }
        $tokens[] = '+' . $part . '*';
    }
    if (empty($tokens)) {
        return $search;
    }
    return implode(' ', $tokens);
}

/**
 * Check if fulltext search is available for tickets.
 */
function tickets_fulltext_available()
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $rows = db_fetch_all("SHOW INDEX FROM tickets WHERE Index_type = 'FULLTEXT'");
        foreach ($rows as $row) {
            if (($row['Key_name'] ?? '') === 'idx_ticket_search') {
                $available = true;
                return $available;
            }
        }
    } catch (Exception $e) {
        // Ignore
    }
    $available = false;
    return $available;
}

/**
 * Check if tags column exists on tickets table.
 */
function ticket_tags_column_exists()
{
    return column_exists('tickets', 'tags');
}

/**
 * Build ticket WHERE clause and parameters.
 */
function build_ticket_where_clause($filters, &$params)
{
    $sql = " WHERE 1=1";
    $has_ticket_access = ticket_access_table_exists();

    if (!empty($filters['status_id'])) {
        $sql .= " AND t.status_id = ?";
        $params[] = $filters['status_id'];
    }

    if (!empty($filters['user_id'])) {
        $sql .= " AND t.user_id = ?";
        $params[] = $filters['user_id'];
    }

    if (!empty($filters['viewer_user_id'])) {
        $viewer_id = (int) $filters['viewer_user_id'];
        if ($has_ticket_access) {
            $sql .= " AND (t.user_id = ? OR EXISTS (SELECT 1 FROM ticket_access ta WHERE ta.ticket_id = t.id AND ta.user_id = ?))";
            $params[] = $viewer_id;
            $params[] = $viewer_id;
        } else {
            $sql .= " AND t.user_id = ?";
            $params[] = $viewer_id;
        }
    }

    if (!empty($filters['organization_id'])) {
        $sql .= " AND t.organization_id = ?";
        $params[] = $filters['organization_id'];
    }

    if (!empty($filters['priority_id'])) {
        $sql .= " AND t.priority_id = ?";
        $params[] = $filters['priority_id'];
    }

    if (!empty($filters['type_id'])) {
        $sql .= " AND t.type_id = ?";
        $params[] = $filters['type_id'];
    }

    if (!empty($filters['assigned_to'])) {
        $sql .= " AND t.assignee_id = ?";
        $params[] = $filters['assigned_to'];
    }

    if (isset($filters['is_archived'])) {
        $sql .= " AND t.is_archived = ?";
        $params[] = $filters['is_archived'] ? 1 : 0;
    }

    if (!empty($filters['created_from'])) {
        $created_from = trim((string) $filters['created_from']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $created_from)) {
            $created_from .= ' 00:00:00';
        }
        $sql .= " AND t.created_at >= ?";
        $params[] = $created_from;
    }

    if (!empty($filters['created_to'])) {
        $created_to = trim((string) $filters['created_to']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $created_to)) {
            $created_to .= ' 23:59:59';
        }
        $sql .= " AND t.created_at <= ?";
        $params[] = $created_to;
    }

    if (ticket_tags_column_exists()) {
        $raw_tags = [];
        if (!empty($filters['tags'])) {
            if (is_array($filters['tags'])) {
                $raw_tags = $filters['tags'];
            } else {
                $raw_tags = explode(',', (string) $filters['tags']);
            }
        }
        if (!empty($filters['tag'])) {
            $raw_tags[] = $filters['tag'];
        }

        $tags = [];
        $seen_tags = [];
        foreach ((array) $raw_tags as $raw_tag) {
            $tag = trim((string) $raw_tag);
            $tag = ltrim($tag, '#');
            $tag = preg_replace('/\s+/', ' ', $tag);
            if ($tag === '') {
                continue;
            }
            $tag_key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
            if (isset($seen_tags[$tag_key])) {
                continue;
            }
            $seen_tags[$tag_key] = true;
            $tags[] = $tag;
        }

        if (!empty($tags)) {
            $tag_clauses = [];
            foreach ($tags as $tag) {
                $tag_clauses[] = "FIND_IN_SET(?, REPLACE(IFNULL(t.tags, ''), ', ', ',')) > 0";
                $params[] = $tag;
            }
            $sql .= " AND (" . implode(' OR ', $tag_clauses) . ")";
        }
    }

    // Search functionality
    if (!empty($filters['search'])) {
        $search = trim($filters['search']);
        $has_tags = ticket_tags_column_exists();
        if (tickets_fulltext_available()) {
            $search_query = build_ticket_fulltext_query($search);
            $sql .= " AND (MATCH(t.title, t.description) AGAINST (? IN BOOLEAN MODE)";
            $params[] = $search_query;
            if ($has_tags) {
                $sql .= " OR t.tags LIKE ?";
                $params[] = '%' . $search . '%';
            }
            if (ctype_digit($search)) {
                $sql .= " OR t.id = ?";
                $params[] = (int) $search;
            }
            $sql .= ")";
        } else {
            $sql .= " AND (t.title LIKE ? OR t.description LIKE ?";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            if ($has_tags) {
                $sql .= " OR t.tags LIKE ?";
                $params[] = '%' . $search . '%';
            }
            if (ctype_digit($search)) {
                $sql .= " OR t.id = ?";
                $params[] = (int) $search;
            }
            $sql .= ")";
        }
    }

    // Due date filters
    $due_date_filter = $filters['due_date_filter'] ?? null;
    if (!empty($filters['due_date_overdue'])) {
        $due_date_filter = 'overdue';
    } elseif (!empty($filters['due_date_today'])) {
        $due_date_filter = 'today';
    } elseif (!empty($filters['due_date_week'])) {
        $due_date_filter = 'this_week';
    }

    if (!empty($due_date_filter)) {
        switch ($due_date_filter) {
            case 'overdue':
                $sql .= " AND t.due_date IS NOT NULL AND DATE(t.due_date) < CURDATE()";
                break;
            case 'today':
                $sql .= " AND DATE(t.due_date) = CURDATE()";
                break;
            case 'this_week':
                // Match current calendar week (Monday to Sunday)
                $sql .= " AND DATE(t.due_date) BETWEEN DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY) AND DATE_ADD(DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY), INTERVAL 6 DAY)";
                break;
        }
    }

    if (!empty($filters['due_date_from'])) {
        $sql .= " AND t.due_date >= ?";
        $params[] = $filters['due_date_from'];
    }
    if (!empty($filters['due_date_to'])) {
        $sql .= " AND t.due_date < ?";
        $params[] = $filters['due_date_to'];
    }

    // Admin staff scope filter (dashboard links)
    if (!empty($filters['assigned_to_staff'])) {
        $sql .= " AND t.assignee_id IN (SELECT id FROM users WHERE role IN ('agent', 'admin'))";
    }

    // Agent scope filter
    if (!empty($filters['agent_id'])) {
        $agent_id = (int) $filters['agent_id'];
        if ($has_ticket_access) {
            $sql .= " AND (t.assignee_id = ? OR t.user_id = ? OR EXISTS (SELECT 1 FROM ticket_access ta WHERE ta.ticket_id = t.id AND ta.user_id = ?))";
            $params[] = $agent_id;
            $params[] = $agent_id;
            $params[] = $agent_id;
        } else {
            $sql .= " AND (t.assignee_id = ? OR t.user_id = ?)";
            $params[] = $agent_id;
            $params[] = $agent_id;
        }
    }

    // Agent/User scope filter based on 'scope' parameter (Used for Organization/Groups)
    if (!empty($filters['current_user']) && !empty($filters['scope'])) {
        $current_user = $filters['current_user'];
        if ($current_user['role'] === 'agent' || $current_user['role'] === 'user') {
            switch ($filters['scope']) {
                case 'assigned':
                    $sql .= " AND t.assignee_id = ?";
                    $params[] = $current_user['id'];
                    break;
                case 'organization':
                    // Get organization IDs from user permissions
                    $org_ids = get_user_organization_ids($current_user['id']);
                    if (!empty($org_ids)) {
                        $placeholders = implode(',', array_fill(0, count($org_ids), '?'));
                        if ($has_ticket_access) {
                            $sql .= " AND (t.organization_id IN ($placeholders) OR t.user_id = ? OR EXISTS (SELECT 1 FROM ticket_access ta WHERE ta.ticket_id = t.id AND ta.user_id = ?))";
                            foreach ($org_ids as $org_id) {
                                $params[] = $org_id;
                            }
                            $params[] = $current_user['id'];
                            $params[] = $current_user['id'];
                        } else {
                            $sql .= " AND (t.organization_id IN ($placeholders) OR t.user_id = ?)";
                            foreach ($org_ids as $org_id) {
                                $params[] = $org_id;
                            }
                            $params[] = $current_user['id'];
                        }
                    } else {
                        // Fallback: Own tickets + Shared
                        if ($has_ticket_access) {
                            $sql .= " AND (t.user_id = ? OR EXISTS (SELECT 1 FROM ticket_access ta WHERE ta.ticket_id = t.id AND ta.user_id = ?))";
                            $params[] = $current_user['id'];
                            $params[] = $current_user['id'];
                        } else {
                            $sql .= " AND t.user_id = ?";
                            $params[] = $current_user['id'];
                        }
                    }
                    break;
                case 'own':
                    // Explicit self-only scope
                    if ($has_ticket_access) {
                        $sql .= " AND (t.user_id = ? OR EXISTS (SELECT 1 FROM ticket_access ta WHERE ta.ticket_id = t.id AND ta.user_id = ?))";
                        $params[] = $current_user['id'];
                        $params[] = $current_user['id'];
                    } else {
                        $sql .= " AND t.user_id = ?";
                        $params[] = $current_user['id'];
                    }
                    break;
            }
        }
    }

    return $sql;
}

