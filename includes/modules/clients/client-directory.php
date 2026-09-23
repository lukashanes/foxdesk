<?php
/** Bounded display for a tenant-scoped client collection. No records are silently discarded. */
function client_directory_page(array $clients, array $request, array $counts = [], array $minutes = []): array
{
    $search = trim(is_scalar($request['search'] ?? null) ? (string) $request['search'] : '');
    $state = in_array($request['state'] ?? '', ['active', 'inactive'], true) ? $request['state'] : '';
    $sort = in_array($request['sort'] ?? '', ['name', 'time', 'records'], true) ? $request['sort'] : 'records';
    $rows = array_values(array_filter($clients, static function ($client) use ($search, $state) {
        if ($state !== '' && (bool) $client['is_active'] !== ($state === 'active')) return false;
        return $search === '' || mb_stripos(($client['name'] ?? '') . ' ' . ($client['contact_email'] ?? '') . ' ' . ($client['ico'] ?? ''), $search) !== false;
    }));
    usort($rows, static function ($a, $b) use ($sort, $counts, $minutes) {
        $rank = $sort === 'time' ? (($minutes[$b['id']] ?? 0) <=> ($minutes[$a['id']] ?? 0))
            : ($sort === 'records' ? (($counts[$b['id']] ?? 0) <=> ($counts[$a['id']] ?? 0)) : 0);
        return $rank ?: strnatcasecmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']);
    });
    $size = is_scalar($request['page_size'] ?? null) ? (string) $request['page_size'] : '20';
    if (!in_array($size, ['10', '20', '50', 'all'], true)) $size = '20';
    $count = count($rows);
    $pages = $size === 'all' ? 1 : max(1, (int) ceil($count / (int) $size));
    $page = max(1, min($pages, (int) ($request['p'] ?? 1)));
    if ($size !== 'all') $rows = array_slice($rows, ($page - 1) * (int) $size, (int) $size);
    return compact('rows', 'search', 'state', 'sort', 'size', 'count', 'page', 'pages');
}
