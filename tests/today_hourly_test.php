<?php
declare(strict_types=1);
require_once __DIR__ . '/../web/narcissus_data.php';

$dir = sys_get_temp_dir() . '/narc_today_' . getmypid();
@mkdir($dir);
$tz = new DateTimeZone('Europe/Amsterdam');
$now = new DateTimeImmutable('2026-10-22 17:20:00', $tz);
$pages = [];
foreach (['a' => ['00:10', '09:05', '09:59', '17:01', '17:30'], 'b' => ['09:30', '23:00'], 'c' => []] as $name => $times) {
    $path = "$dir/$name.sqlite";
    @unlink($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->exec('CREATE TABLE visits (id INTEGER PRIMARY KEY AUTOINCREMENT, visited_at INTEGER NOT NULL, user_email TEXT NOT NULL)');
    foreach ($times as $t) {
        $ts = (new DateTimeImmutable("2026-10-22 $t", $tz))->getTimestamp();
        $pdo->exec("INSERT INTO visits (visited_at, user_email) VALUES ($ts, 'x@kvt.nl')");
    }
    $yesterday = (new DateTimeImmutable('2026-10-21 23:59', $tz))->getTimestamp();
    $pdo->exec("INSERT INTO visits (visited_at, user_email) VALUES ($yesterday, 'x@kvt.nl')");
    $pdo = null;
    $pages[] = ['id' => $name, 'name' => $name, 'path' => $path];
}
$pages[] = ['id' => 'missing', 'name' => 'missing', 'path' => "$dir/nope/x.sqlite"];

$r = narcissus_today_hourly($pages, $now);
$fail = 0;
$check = function (bool $ok, string $msg) use (&$fail) { if (!$ok) { $fail++; echo "FAIL: $msg\n"; } };
$check($r['date'] === '2026-10-22', 'date');
$check(count($r['labels']) === 24, 'labels');
$check(count($r['data']) === 18, 'data stopt bij huidig uur');
$check($r['data'][0] === 1, 'uur 0');
$check($r['data'][9] === 3, 'uur 9 opgeteld over apps');
$check($r['data'][17] === 1, 'uur 17 (17:30 ligt na nu)');
$check($r['total'] === 5, 'totaal zonder gisteren/toekomst');
$check(abs($r['now_hour'] - 17.333) < 0.01, 'now_hour');
array_map('unlink', glob("$dir/*.sqlite"));
@rmdir($dir);
echo $fail === 0 ? "OK\n" : "$fail failures\n";
exit($fail === 0 ? 0 : 1);
