<?php
declare(strict_types=1);
require_once __DIR__ . '/../web/narcissus_data.php';

$dir = sys_get_temp_dir() . '/narc_today_' . getmypid();
@mkdir($dir);
$tz = new DateTimeZone('Europe/Amsterdam');
$now = new DateTimeImmutable('2026-10-22 17:22:00', $tz);
$pages = [];
foreach (['a' => ['00:00', '00:04', '09:05', '09:09', '17:21', '17:30'], 'b' => ['09:06', '23:00'], 'c' => []] as $name => $times) {
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
$byX = [];
foreach ($r['points'] as $p) { $byX[$p['x']] = $p['y']; }
$fail = 0;
$check = function (bool $ok, string $msg) use (&$fail) { if (!$ok) { $fail++; echo "FAIL: $msg\n"; } };
$check($r['date'] === '2026-10-22', 'date');
$check($r['bucket_minutes'] === 5, 'bucket 5 min');
$check(count($r['points']) === 17 * 12 + 5, 'punten t/m bucket van nu (17:20)');
$check(end($r['points'])['x'] === 17 * 60 + 20, 'laatste bucket 17:20');
$check($byX[0] === 2, '00:00-00:05');
$check($byX[545] === 3 && $byX[540] === 0, '09:05 opgeteld over apps');
$check($byX[1040] === 1, '17:20');
$check($r['total'] === 6, 'totaal zonder gisteren/toekomst');
$check($r['now_minute'] === 1042, 'now_minute');
$full = narcissus_today_hourly($pages, new DateTimeImmutable('2026-10-22 23:59:30', $tz));
$check(count($full['points']) === 288, '288 punten aan eind van de dag');
array_map('unlink', glob("$dir/*.sqlite"));
@rmdir($dir);
echo $fail === 0 ? "OK\n" : "$fail failures\n";
exit($fail === 0 ? 0 : 1);
