<?php

require_once dirname(__DIR__) . '/web/narcissus_data.php';

function heatmap_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function heatmap_assert_same(string $expected, string $actual, string $message): void
{
    heatmap_assert($expected === $actual, $message . " (expected {$expected}, got {$actual})");
}

$zone = new DateTimeZone('Europe/Amsterdam');

heatmap_assert(NARCISSUS_HEATMAP_ROWS === 7, 'heatmap rows constant is 7');
heatmap_assert(NARCISSUS_PAGE_RANK_LIMIT === 20, 'page rank limit is 20');
heatmap_assert(narcissus_heatmap_rows() === 7, 'narcissus_heatmap_rows() is 7');

$wednesday = new DateTimeImmutable('2026-09-30', $zone);
$range = narcissus_heatmap_date_range(3, null, $wednesday);
heatmap_assert($range['cols'] === 3, 'wednesday cols');
heatmap_assert($range['rows'] === 7, 'wednesday rows');
heatmap_assert_same('2026-09-14', $range['from'], 'wednesday from is Monday three weeks back');
heatmap_assert_same('2026-10-04', $range['to'], 'wednesday to is Sunday of the current week');

$labels = narcissus_day_labels($range['from'], $range['to']);
heatmap_assert(count($labels) === 21, 'three ISO weeks are 21 days');
heatmap_assert_same('2026-09-14', $labels[0], 'first label');
heatmap_assert_same('2026-10-04', $labels[20], 'last label');
foreach ($labels as $index => $label) {
    $date = new DateTimeImmutable($label, $zone);
    $expectedDow = (string) (($index % 7) + 1);
    heatmap_assert($date->format('N') === $expectedDow, "day {$label} is ISO weekday {$expectedDow}");
}

$sunday = new DateTimeImmutable('2026-10-04', $zone);
$sundayRange = narcissus_heatmap_date_range(2, null, $sunday);
heatmap_assert_same('2026-09-21', $sundayRange['from'], 'sunday range starts on the previous Monday');
heatmap_assert_same('2026-10-04', $sundayRange['to'], 'sunday range ends on that Sunday');

$monday = new DateTimeImmutable('2026-09-28', $zone);
$mondayRange = narcissus_heatmap_date_range(1, null, $monday);
heatmap_assert_same('2026-09-28', $mondayRange['from'], 'single week starts Monday');
heatmap_assert_same('2026-10-04', $mondayRange['to'], 'single week ends Sunday');

echo "heatmap_range_test OK\n";
