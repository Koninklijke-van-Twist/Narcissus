<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/narcissus_data.php';

/**
 * Page load
 */

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$pages = narcissus_discover_pages();

if ($action === 'pages') {
    narcissus_json([
        'ok' => true,
        'pages' => array_map(static function (array $page): array {
            return [
                'id' => (string) $page['id'],
                'name' => (string) $page['name'],
            ];
        }, $pages),
    ]);
}

if ($action === 'chart') {
    $from = narcissus_parse_date((string) ($_GET['from'] ?? ''));
    $to = narcissus_parse_date((string) ($_GET['to'] ?? ''));
    if ($from === '') {
        $from = narcissus_default_date_from();
    }
    if ($to === '') {
        $to = narcissus_default_date_to();
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    narcissus_json([
        'ok' => true,
        'chart' => narcissus_chart_data($pages, $from, $to),
    ]);
}

if ($action === 'top' || $action === 'search') {
    $page = narcissus_page_by_id((string) ($_GET['page'] ?? ''), $pages);
    if ($page === null) {
        narcissus_json(['ok' => false, 'error' => 'Onbekende pagina'], 404);
    }

    if ($action === 'top') {
        narcissus_json([
            'ok' => true,
            'page' => [
                'id' => (string) $page['id'],
                'name' => (string) $page['name'],
            ],
            'users' => narcissus_top_users((string) $page['path'], 10),
        ]);
    }

    $query = trim((string) ($_GET['q'] ?? ''));
    narcissus_json([
        'ok' => true,
        'page' => [
            'id' => (string) $page['id'],
            'name' => (string) $page['name'],
        ],
        'query' => $query,
        'users' => narcissus_search_users((string) $page['path'], $query, 10),
    ]);
}

narcissus_json(['ok' => false, 'error' => 'Onbekende actie'], 400);
