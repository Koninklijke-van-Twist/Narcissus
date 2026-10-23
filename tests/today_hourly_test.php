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
$check($r['bucket_minutes'] === 15, 'bucket 15 min');
$check(count($r['points']) === 17 * 4 + 2, 'punten t/m restant van nu (17:22)');
$check(end($r['points'])['x'] === 1042 && end($r['points'])['y'] === 1, 'laatste punt op nu, 17:15-17:22');
$gridOk = true;
foreach ($r['points'] as $i => $p) {
    if ($i < count($r['points']) - 1 && $p['x'] !== $i * 15) {
        $gridOk = false;
    }
}
$check($gridOk, 'eerdere punten op blokstart');
$check($byX[0] === 2, '00:00-00:15');
$check($byX[540] === 3 && ($byX[555] ?? 0) === 0, '09:00-09:15 opgeteld over apps');
$check(!isset($byX[1050]), 'geen punt op de volgende grens');
$check($r['total'] === 6, 'totaal zonder gisteren/toekomst');
$check($r['now_minute'] === 1042, 'now_minute');
$edge = narcissus_today_hourly($pages, new DateTimeImmutable('2026-10-22 17:15:00', $tz));
$check(end($edge['points'])['x'] === 17 * 60 + 15 && end($edge['points'])['y'] === 0, 'op de grens eindigt de lijn op nu');
$check($edge['total'] === 5, '17:21 telt niet mee op 17:15');
$full = narcissus_today_hourly($pages, new DateTimeImmutable('2026-10-22 23:59:30', $tz));
$fullByX = [];
foreach ($full['points'] as $p) { $fullByX[$p['x']] = $p['y']; }
$check(count($full['points']) === 96, '96 punten aan eind van de dag');
$check(end($full['points'])['x'] === 23 * 60 + 59, 'lijn eindigt op 23:59');
$check(($fullByX[23 * 60] ?? 0) === 1 && ($fullByX[17 * 60 + 30] ?? 0) === 1, '17:30 en 23:00 tellen later mee');
$check($full['total'] === 8, 'totaal aan eind van de dag');
array_map('unlink', glob("$dir/*.sqlite"));
@rmdir($dir);
echo $fail === 0 ? "OK\n" : "$fail failures\n";
exit($fail === 0 ? 0 : 1);
