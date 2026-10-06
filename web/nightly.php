<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/narcissus_data.php';
require_once __DIR__ . '/bc_usage.php';

/**
 * Page load
 */

ignore_user_abort(true);
set_time_limit(0);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
ob_start();

$startedAt = microtime(true);
$failed = false;

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
    $failed = true;
    echo "Narcissus nightly heatmap cache FAILED\n";
    echo $error->getMessage() . "\n";
}

// BC Gebruik: alleen tellingen en bronstatus in de uitvoer, geen namen (persoonsgegevens).
$bcStartedAt = microtime(true);
try {
    $bcUsage = narcissus_bc_usage_refresh(null, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY);
    echo "\nNarcissus nightly BC Gebruik " . ($bcUsage['kept_previous'] ? 'ONVOLLEDIG (vorige gegevens blijven staan)' : 'OK') . "\n";
    echo 'written=' . ($bcUsage['written'] ? 'yes' : 'no') . "\n";
    echo 'users=' . (int) $bcUsage['users'] . "\n";
    if (is_array($bcUsage['users_diagnostics'] ?? null)) {
        foreach (narcissus_bc_usage_diagnostics_lines($bcUsage['users_diagnostics']) as $line) {
            echo $line . "\n";
        }
    }
    foreach ($bcUsage['sources'] as $source) {
        echo 'source=' . (string) ($source['source'] ?? '') . ' status=' . (string) ($source['status'] ?? '')
            . ' rows=' . (int) ($source['rows'] ?? 0) . "\n";
        if ((string) ($source['message'] ?? '') !== '') {
            echo '  ' . (string) $source['message'] . "\n";
        }
    }
    echo 'elapsed_s=' . round(microtime(true) - $bcStartedAt, 1) . "\n";
    if (!$bcUsage['written']) {
        $failed = true;
    }
} catch (Throwable $error) {
    $failed = true;
    echo "\nNarcissus nightly BC Gebruik FAILED\n";
    echo $error->getMessage() . "\n";
}

if ($failed) {
    http_response_code(500);
}
