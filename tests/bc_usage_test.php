<?php

require_once dirname(__DIR__) . '/web/bc_usage.php';
require_once __DIR__ . '/fixtures/bc_usage_fixture.php';

$assertions = 0;

function bcu_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if ($condition) {
        return;
    }

    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function bcu_same($expected, $actual, string $message): void
{
    bcu_assert($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function bcu_temp_path(): string
{
    $dir = sys_get_temp_dir() . '/narcissus-bcu-' . bin2hex(random_bytes(4));
    return $dir . '/data/' . NARCISSUS_BC_USAGE_DATA_FILE;
}

function bcu_cleanup(string $path): void
{
    $dir = dirname($path);
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
    @rmdir(dirname($dir));
}

function bcu_find(array $rows, string $user): ?array
{
    foreach ($rows as $row) {
        if (strcasecmp((string) $row['user'], $user) === 0) {
            return $row;
        }
    }
    return null;
}

$zone = new DateTimeZone('Europe/Amsterdam');
$today = new DateTimeImmutable('2026-10-06', $zone); // dinsdag
$now = (new DateTimeImmutable('2026-10-06 03:12:00', $zone))->getTimestamp();
$GLOBALS['mimirApi'] = 'mimir_testsleutel';

/* ---------- Opmaak: Nederlands, Europe/Amsterdam ---------- */
bcu_same('6 oktober 2026', narcissus_format_dutch_date('2026-10-06'), 'Nederlandse datum');
bcu_same('1 januari 2026', narcissus_format_dutch_date('2026-01-01'), 'januari');
bcu_same('—', narcissus_format_dutch_date('2026-13-01'), 'ongeldige datum');
bcu_same('6 oktober 2026 om 03:12', narcissus_format_dutch_datetime((new DateTimeImmutable('2026-10-06T01:12:00Z'))->getTimestamp()), 'UTC naar Amsterdam (zomertijd)');
bcu_same('15 januari 2026 om 10:00', narcissus_format_dutch_datetime((new DateTimeImmutable('2026-01-15T09:00:00Z'))->getTimestamp()), 'UTC naar Amsterdam (wintertijd)');
bcu_same('07:35', narcissus_format_minutes_hhmm(455), 'HH:MM');
bcu_same('52:28', narcissus_format_minutes_hhmm(3148), 'HH:MM boven 24 uur');
bcu_same('00:00', narcissus_format_minutes_hhmm(-5), 'negatief wordt 00:00');

/* ---------- Venster ---------- */
$window = narcissus_bc_usage_window($today);
bcu_same('2026-04-13', $window['from'], 'venster begint op maandag 25 weken voor deze week');
bcu_same('2026-10-11', $window['to'], 'venster eindigt op zondag van deze week');
bcu_same('2026-09-07', $window['recent_from'], 'laatste 30 dagen incl. vandaag');
bcu_same(26, $window['cols'], '26 weken');

/* ---------- Beheerders (fail-closed) ---------- */
bcu_assert(narcissus_is_admin('Tim@KVT.nl ', ['tim@kvt.nl']), 'beheerder hoofdletterongevoelig');
bcu_assert(narcissus_is_admin('tim@kvt.nl', ['tim@kvt.nl' => true]), 'beheerder als sleutel');
bcu_assert(!narcissus_is_admin('collega@kvt.nl', ['tim@kvt.nl']), 'geen beheerder');
bcu_assert(!narcissus_is_admin('tim@kvt.nl', []), 'lege lijst = niemand');
bcu_assert(!narcissus_is_admin('', ['']), 'leeg e-mailadres nooit beheerder');
unset($GLOBALS['admins']);
bcu_assert(!narcissus_is_admin('tim@kvt.nl'), 'zonder $admins niemand');
bcu_same('', narcissus_tabs_html('pagina', false), 'niet-beheerder ziet geen tabs');
bcu_assert(str_contains(narcissus_tabs_html('bc-gebruik', true), 'bc_gebruik.php'), 'beheerder ziet BC Gebruik-tab');
bcu_assert(str_contains(narcissus_tabs_html('bc-gebruik', true), 'aria-current="page">BC Gebruik'), 'actieve tab');

/* ---------- Kleine fixture: exacte waarden ---------- */
$licensed = narcissus_bc_usage_licensed_users([
    ['User_Name' => 'KVT\\ALICE', 'Full_Name' => 'Alice', 'State' => 'Enabled', 'License_Type' => 'Full User'],
    ['User_Name' => 'KVT\\BOB', 'Full_Name' => 'Bob', 'State' => 'Enabled', 'License_Type' => 'Limited User'],
    ['User_Name' => 'KVT\\CAROL', 'Full_Name' => '', 'State' => 'Enabled', 'License_Type' => 'Device Only User'],
    ['User_Name' => 'KVT\\DAVE', 'Full_Name' => 'Dave', 'State' => 'Disabled', 'License_Type' => 'Full User'],
    ['User_Name' => 'KVT\\APP', 'Full_Name' => 'App', 'State' => 'Enabled', 'License_Type' => 'Application'],
    ['User_Name' => '', 'Full_Name' => 'Leeg', 'State' => 'Enabled', 'License_Type' => 'Full User'],
]);
bcu_same(['KVT\\ALICE', 'KVT\\BOB', 'KVT\\CAROL'], array_keys($licensed), 'alleen Enabled + Full/Limited/Device Only');
bcu_same('KVT\\CAROL', $licensed['KVT\\CAROL']['name'], 'lege Full_Name valt terug op User_Name');
bcu_assert(!isset($licensed['KVT\\ALICE']['email']) && !isset($licensed['KVT\\ALICE']['sid']), 'geen e-mail of SID bewaard');

$aggregated = narcissus_bc_usage_aggregate($licensed, [
    [
        ['User_ID' => 'kvt\\alice ', 'Date' => '2026-10-06', 'Minutes' => 300],
        ['User_ID' => 'KVT\\ALICE', 'Date' => '2026-10-05', 'Minutes' => 120],
        ['User_ID' => 'KVT\\ALICE', 'Date' => '2026-03-01', 'Minutes' => 999], // vóór het venster
        ['User_ID' => 'KVT\\DAVE', 'Date' => '2026-10-06', 'Minutes' => 500], // disabled
        ['User_ID' => 'KVT\\BOB', 'Date' => '2026-08-01', 'Minutes' => 0],
        ['User_ID' => 'KVT\\BOB', 'Date' => '2026-08-03', 'Minutes' => 45],
    ],
    [
        ['User_ID' => 'KVT\\ALICE', 'Date' => '2026-10-06', 'Minutes' => 155], // HVT, zelfde dag
    ],
], $window['from'], $window['today']);
$alice = bcu_find($aggregated, 'KVT\\ALICE');
bcu_same(['2026-10-05' => 120, '2026-10-06' => 455], $alice['days'], 'minuten per dag over bedrijven opgeteld, join case-insensitive + trim');
bcu_same([], bcu_find($aggregated, 'KVT\\CAROL')['days'], 'gebruiker zonder registraties blijft in de lijst');
bcu_same(null, bcu_find($aggregated, 'KVT\\DAVE'), 'disabled gebruiker valt weg');

$small = ['version' => 1, 'generated_at' => $now, 'sources' => [], 'users' => $aggregated];
$summary = narcissus_bc_usage_summary($small, $today, $now);
$aliceRow = bcu_find($summary['users'], 'KVT\\ALICE');
bcu_same(2, $aliceRow['active_30'], 'Alice actief 30 d');
bcu_same(2, $aliceRow['active_window'], 'Alice actief venster');
bcu_same(575, $aliceRow['total_minutes'], 'Alice totaal');
bcu_same(288, $aliceRow['avg_minutes'], 'Alice gemiddelde (afgerond)');
bcu_same('04:48', $aliceRow['avg_label'], 'Alice gemiddelde HH:MM');
bcu_same('2026-10-06', $aliceRow['last_day'], 'Alice laatste dag');
bcu_same('6 oktober 2026', $aliceRow['last_day_label'], 'Alice laatste dag leesbaar');
bcu_assert($aliceRow['low'], 'Alice: 2 actieve dagen < 4 = weinig gebruik');
$bobRow = bcu_find($summary['users'], 'KVT\\BOB');
bcu_same(0, $bobRow['active_30'], 'Bob niet actief in 30 d');
bcu_same(64, $bobRow['days_since_last'], 'Bob dagen sinds laatste registratie');
bcu_same(2, count($bobRow['low_reasons']), 'Bob: twee redenen');
$carolRow = bcu_find($summary['users'], 'KVT\\CAROL');
bcu_same(null, $carolRow['last_day'], 'Carol geen laatste dag');
bcu_same('Niet in het venster', $carolRow['last_day_label'], 'Carol label');
bcu_same(455, $summary['ceiling_minutes'], 'plafond = hoogste dagwaarde');
bcu_same(3, $summary['low_count'], 'alle drie weinig gebruik');
bcu_same('13 april 2026 t/m 6 oktober 2026', $summary['window_label'], 'venster leesbaar');
bcu_same('6 oktober 2026 om 03:12', $summary['generated_at_label'], 'bijgewerkt leesbaar');
bcu_assert(!$summary['stale'], 'niet verouderd');
bcu_assert(narcissus_bc_usage_summary($small, $today, $now + 37 * 3600)['stale'], 'verouderd na 36 uur');
bcu_assert(!narcissus_bc_usage_summary(null, $today, $now)['has_data'], 'zonder bestand geen data');

$heatmap = narcissus_bc_usage_user_heatmap($small, ' kvt\\alice', $today);
bcu_same(182, count($heatmap['days']), 'heatmap: 26 weken × 7 dagen');
bcu_same('2026-04-13', $heatmap['days'][0]['date'], 'heatmap begint op maandag');
bcu_same(455, $heatmap['days'][176]['count'], 'heatmap vandaag = 455 minuten');
bcu_assert(!$heatmap['days'][176]['future'] && $heatmap['days'][177]['future'], 'dagen na vandaag zijn toekomst');
bcu_same(null, narcissus_bc_usage_user_heatmap($small, 'KVT\\ONBEKEND', $today), 'onbekende gebruiker');

/* ---------- Realistische fixture (~160 gebruikers, 6 maanden) via nep-Mímir ---------- */
$fixture = bc_usage_fixture('2026-10-06');
bcu_same(199, count($fixture['users']), 'fixture: 199 rijen in Users');
$path = bcu_temp_path();
$requests = [];
$result = narcissus_bc_usage_refresh($today, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, bc_usage_fixture_transport($fixture, [], $requests), $path, $now);
bcu_assert($result['written'] && !$result['kept_previous'], 'realistisch: geschreven');
bcu_same(160, $result['users'], 'realistisch: 160 gelicentieerde gebruikers');
bcu_same(['ok', 'ok', 'ok', 'ok'], array_column($result['sources'], 'status'), 'alle vier bronnen ok');
bcu_same(4, count($requests), 'vier Mímir-verzoeken');
bcu_same('https://sleutels.kvt.nl/mimir/api/query.php', $requests[0]['url'], 'Mímir query.php');
bcu_same('mimir_testsleutel', $requests[0]['api_key'], 'sleutel uit $mimirApi');
bcu_same(['Koninklijke van Twist', 'Users', 14400, 0], [$requests[0]['body']['company'], $requests[0]['body']['table'], $requests[0]['body']['max_age'], $requests[0]['body']['top']], 'Users: KVT, max_age 4 uur, top 0');
bcu_same(NARCISSUS_BC_USAGE_USERS_SELECT, $requests[0]['body']['select'], 'Users select');
bcu_assert(!isset($requests[0]['body']['filter']), 'Users zonder filter (deelt cache)');
bcu_same(['Koninklijke van Twist', 'Hunter van Twist', 'KVT Gas'], array_map(static function (array $r): string {
    return $r['body']['company'];
}, array_slice($requests, 1)), 'UserTimeRegisters per bedrijf');
bcu_same(['and' => [['field' => 'Date', 'op' => 'ge', 'value' => '2026-04-13']]], $requests[1]['body']['filter'], 'Date ge vensterbegin');
bcu_same(['User_ID', 'Date', 'Minutes'], $requests[1]['body']['select'], 'UserTimeRegisters select');

$raw = (string) file_get_contents($path);
bcu_assert(str_starts_with($raw, NARCISSUS_BC_USAGE_DATA_GUARD), 'databestand begint met PHP-guard');
bcu_assert(is_file(dirname($path) . '/.htaccess'), '.htaccess in data-map');
bcu_assert(!str_contains($raw, 'kvt.example') && !str_contains($raw, '-4000-8000-'), 'geen e-mail of SID op schijf');
bcu_assert(!str_contains($raw, 'ONBEKEND'), 'onbekende gebruiker niet op schijf');
bcu_assert(strlen($raw) < 600000, 'databestand blijft klein (' . strlen($raw) . ' bytes)');

$data = narcissus_bc_usage_read($path);
bcu_assert(is_array($data), 'teruglezen');
$summary = narcissus_bc_usage_summary($data, $today, $now);
bcu_same(160, $summary['user_count'], 'summary: 160 gebruikers');
$licenses = array_count_values(array_column($summary['users'], 'license'));
bcu_same(['Device Only User' => 1, 'Full User' => 157, 'Limited User' => 2], (ksort($licenses) ? $licenses : []), 'licentietypes');
$byProfile = [];
foreach ($summary['users'] as $row) {
    $byProfile[$fixture['profiles'][$row['user']]][] = $row;
}
foreach ($byProfile['none'] as $row) {
    bcu_assert($row['low'] && $row['active_window'] === 0 && $row['last_day'] === null, 'zonder Register Time: weinig gebruik, geen dagen');
}
foreach ($byProfile['stopped'] as $row) {
    bcu_assert($row['low'] && $row['active_30'] === 0 && $row['days_since_last'] >= 45, 'gestopt: laatste dag > 30 dagen');
}
foreach ($byProfile['sporadic'] as $row) {
    bcu_assert($row['low'], 'sporadisch: weinig gebruik');
}
foreach ($byProfile['heavy'] as $row) {
    bcu_assert(!$row['low'] && $row['active_30'] >= 15, 'zwaar: geen signaal en veel actieve dagen');
}
$expectedLow = count($byProfile['none']) + count($byProfile['stopped']) + count($byProfile['sporadic']);
bcu_assert($summary['low_count'] >= $expectedLow && $summary['low_count'] <= $expectedLow + 3, 'aantal weinig gebruik klopt met profielen (' . $summary['low_count'] . ')');
bcu_assert($summary['ceiling_minutes'] > 1440 && $summary['ceiling_minutes'] <= 3150 + 120 + 60, 'plafond = idle-uitschieter boven 24 uur');
$multi = bcu_find($summary['users'], 'KVT\\USER006'); // KVT + HVT
bcu_assert($multi !== null && $multi['total_minutes'] > 0, 'gebruiker met KVT en HVT');
$heat = narcissus_bc_usage_user_heatmap($data, 'KVT\\USER001', $today);
bcu_same(182, count($heat['days']), 'realistische heatmap 182 dagen');
bcu_assert(max(array_column($heat['days'], 'count')) <= $summary['ceiling_minutes'], 'heatmapwaarden onder plafond');

/* ---------- Bron niet gepubliceerd / fout ---------- */
$result = narcissus_bc_usage_refresh($today, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, bc_usage_fixture_transport($fixture, ['UserTimeRegisters|KVT Gas' => 4040]), $path, $now + 60);
bcu_assert($result['written'] && !$result['kept_previous'], 'één bedrijf weg: gedeeltelijk bijgewerkt');
$gas = $result['sources'][3];
bcu_same('niet_gepubliceerd', $gas['status'], '404 met lege body = niet gepubliceerd');
bcu_assert(str_contains($gas['message'], 'UserTimeRegisters (KVT Gas)') && str_contains($gas['message'], 'niet'), 'nette melding per bron');
$summary = narcissus_bc_usage_summary(narcissus_bc_usage_read($path), $today, $now);
bcu_same('niet_gepubliceerd', $summary['sources'][3]['status'], 'status zichtbaar in summary');

$previousRaw = (string) file_get_contents($path);
$result = narcissus_bc_usage_refresh($today, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, bc_usage_fixture_transport($fixture, ['Users' => 404]), $path, $now + 120);
bcu_assert($result['kept_previous'], 'Users weg: vorige gegevens blijven');
$kept = narcissus_bc_usage_read($path);
bcu_same(160, count($kept['users']), 'vorige gebruikers staan er nog');
bcu_same($now + 60, $kept['generated_at'], 'generated_at blijft de laatste gelukte run');
bcu_same($now + 120, $kept['attempted_at'], 'attempted_at bijgewerkt');
bcu_same('niet_gepubliceerd', $kept['sources'][0]['status'], 'Users: Mímir 404 = niet gepubliceerd');
bcu_assert(str_contains($kept['sources'][0]['message'], 'Users (Koninklijke van Twist)'), 'Users-melding');

$result = narcissus_bc_usage_refresh($today, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, bc_usage_fixture_transport($fixture, ['UserTimeRegisters' => 500]), $path, $now + 180);
bcu_assert($result['kept_previous'], 'alle UserTimeRegisters fout: vorige gegevens blijven');
bcu_same(['ok', 'fout', 'fout', 'fout'], array_column($result['sources'], 'status'), 'HTTP 500 = fout');
bcu_same(160, count(narcissus_bc_usage_read($path)['users']), 'gebruikers niet op nul gezet');
bcu_cleanup($path);

$path = bcu_temp_path();
$result = narcissus_bc_usage_refresh($today, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, bc_usage_fixture_transport($fixture, ['Users' => 404]), $path, $now);
bcu_assert($result['written'] && $result['users'] === 0, 'eerste run zonder Users: leeg bestand met status');
bcu_assert(!narcissus_bc_usage_summary(narcissus_bc_usage_read($path), $today, $now)['has_data'], 'geen data, wel status');
bcu_cleanup($path);

unset($GLOBALS['mimirApi']);
$path = bcu_temp_path();
$result = narcissus_bc_usage_refresh($today, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, null, $path, $now);
bcu_same(['niet_ingesteld', 'niet_ingesteld', 'niet_ingesteld', 'niet_ingesteld'], array_column($result['sources'], 'status'), 'zonder $mimirApi: niet ingesteld, geen HTTP');
bcu_cleanup($path);

bcu_same(null, narcissus_bc_usage_read('/nonexistent/' . NARCISSUS_BC_USAGE_DATA_FILE), 'ontbrekend bestand');

echo "bc_usage_test OK ({$assertions} assertions)\n";
