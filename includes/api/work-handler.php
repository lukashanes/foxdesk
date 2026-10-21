<?php
/**
 * Work dashboard read API.
 *
 * Returns the flight deck dashboard read model (money, people, clients, the
 * worked-days chart, attention chips, activity feed and recently updated
 * tickets) as JSON. Every aggregate comes from includes/modules/work and uses
 * the shared ticket visibility predicate; the team scope is admin-only.
 */

function api_work_time_dashboard(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = function_exists('current_user') ? current_user() : null;
    if (!$user) {
        api_error('Unauthorized', 401);
    }
    if (!in_array($user['role'] ?? '', ['admin', 'agent'], true)) {
        api_error('Forbidden', 403);
    }

    require_once BASE_PATH . '/includes/modules/work/flight-deck.php';

    // A read never changes the remembered dashboard period or scope.
    $period = time_activity_period_from_request($_GET + ['period' => 'this_month'], false);
    $scope = time_activity_view_scope_from_request($_GET, ($user['role'] ?? '') === 'admin', false);
    $team = ($scope['key'] ?? 'mine') === 'team';
    $days = max(1, min(62, (int) ($_GET['days'] ?? 14)));

    $money = work_flight_deck_money($user, $period, $team);
    // People and clients always describe the whole visible workspace for the period.
    $totals = $team || ($user['role'] ?? '') !== 'admin' ? $money : work_flight_deck_money($user, $period, true);

    api_success([
        'period' => [
            'key' => (string) ($period['period'] ?? 'this_month'),
            'label' => (string) ($period['label'] ?? ''),
            'start' => $period['start'] ?? null,
            'end' => $period['end'] ?? null,
        ],
        'scope' => (string) ($scope['key'] ?? 'mine'),
        'money' => [
            'minutes' => (int) ($money['minutes'] ?? 0),
            'amount' => round((float) ($money['amount'] ?? 0), 2),
            'reported_amount' => round((float) ($money['reported_amount'] ?? 0), 2),
            'unreported_amount' => round((float) ($money['unreported_amount'] ?? 0), 2),
            'reports_supported' => !empty($money['reports_supported']),
            'to_report' => $money['to_report'] ?? ['tickets' => 0, 'amount' => 0.0],
            'previous_amount' => round((float) ($money['previous_amount'] ?? 0), 2),
            'previous_label' => (string) ($money['previous_label'] ?? ''),
            'change_percent' => $money['change_percent'] ?? null,
            'has_time' => !empty($money['has_time']),
        ],
        'people' => array_values(array_map(static function (array $person): array {
            return [
                'id' => (int) $person['id'],
                'name' => (string) $person['name'],
                'minutes' => (int) $person['minutes'],
                'amount' => round((float) $person['amount'], 2),
            ];
        }, $totals['people'] ?? [])),
        'clients' => array_values(array_map(static function (array $client): array {
            return [
                'id' => (int) $client['id'],
                'name' => (string) $client['name'],
                'minutes' => (int) $client['minutes'],
                'amount' => round((float) $client['amount'], 2),
            ];
        }, $totals['clients'] ?? [])),
        'days' => work_flight_deck_days($user, $team, $days),
        'attention' => work_flight_deck_attention($user, $team, $money),
        'feed' => work_flight_deck_feed_groups(work_flight_deck_feed($user, $team, 10)),
        'recent_tickets' => work_flight_deck_recent_tickets($user, $team, 8),
    ]);
}
