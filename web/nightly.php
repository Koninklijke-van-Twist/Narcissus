<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/narcissus_data.php';

/**
 * Page load
 */

ignore_user_abort(true);
set_time_limit(0);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$startedAt = microtime(true);

try {
    $summary = narcissus_heatmap_cache_rebuild_all();
    $elapsed = round(microtime(true) - $startedAt, 1);

    echo "Narcissus nightly heatmap cache OK\n";
    echo 'pages=' . (int) ($summary['pages'] ?? 0) . "\n";
    echo 'written=' . (int) ($summary['written'] ?? 0) . "\n";
    echo 'through_date=' . (string) ($summary['through_date'] ?? '') . "\n";
    echo 'intensity_max=' . (int) ($summary['intensity_max'] ?? 0) . "\n";
    echo 'elapsed_s=' . $elapsed . "\n";
} catch (Throwable $error) {
    http_response_code(500);
    echo "Narcissus nightly heatmap cache FAILED\n";
    echo $error->getMessage() . "\n";
}
