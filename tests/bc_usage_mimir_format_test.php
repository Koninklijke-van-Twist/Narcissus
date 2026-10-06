<?php

/**
 * BC Gebruik met Users in het formaat dat Mímir live teruggeeft (Nederlandse optie-captions,
 * geprojecteerd op select), plus _xHHHH_-codering, hoofdletters en de nightly-diagnose.
 */

require_once dirname(__DIR__) . '/web/bc_usage.php';
require_once __DIR__ . '/fixtures/bc_usage_fixture.php';

$assertions = 0;

function bcm_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if ($condition) {
        return;
    }

    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function bcm_same($expected, $actual, string $message): void
{
    bcm_assert($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function bcm_temp_path(): string
{
    return sys_get_temp_dir() . '/narcissus-bcm-' . bin2hex(random_bytes(4)) . '/data/' . NARCISSUS_BC_USAGE_DATA_FILE;
}

function bcm_cleanup(string $path): void
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

$zone = new DateTimeZone('Europe/Amsterdam');
$today = new DateTimeImmutable('2026-10-06', $zone);
$now = (new DateTimeImmutable('2026-10-06 03:12:00', $zone))->getTimestamp();
$GLOBALS['mimirApi'] = 'mimir_testsleutel';

/* ---------- Normaliseren ---------- */
bcm_same('Full User', narcissus_bc_usage_decode_xhhhh('Full_x0020_User'), '_x0020_ wordt spatie');
bcm_same('Café', narcissus_bc_usage_decode_xhhhh('Caf_x00E9_'), '_x00E9_ wordt é');
bcm_same('a_xZZZZ_b', narcissus_bc_usage_decode_xhhhh('a_xZZZZ_b'), 'ongeldige reeks blijft staan');
bcm_same('full user', narcissus_bc_usage_normalize_option(' FULL_x0020_USER '), 'genormaliseerd');
bcm_same('full user', narcissus_bc_usage_normalize_option('Full_User'), 'enum-naam met underscore');
bcm_same('', narcissus_bc_usage_normalize_option(['x']), 'geen scalar');

bcm_assert(narcissus_bc_usage_state_enabled('Enabled'), 'Enabled');
bcm_assert(narcissus_bc_usage_state_enabled('Geactiveerd'), 'Geactiveerd (nl-NL caption)');
bcm_assert(narcissus_bc_usage_state_enabled('enabled'), 'hoofdletterongevoelig');
bcm_assert(!narcissus_bc_usage_state_enabled('Gedeactiveerd'), 'Gedeactiveerd');
bcm_assert(!narcissus_bc_usage_state_enabled('Disabled'), 'Disabled');
bcm_assert(!narcissus_bc_usage_state_enabled(null), 'ontbrekend');

bcm_same('Full User', narcissus_bc_usage_license_match('Volwaardige gebruiker'), 'NL Full User');
bcm_same('Full User', narcissus_bc_usage_license_match('Full_x0020_User'), '_x0020_ Full User');
bcm_same('Limited User', narcissus_bc_usage_license_match('Beperkte gebruiker'), 'NL Limited User');
bcm_same('Device Only User', narcissus_bc_usage_license_match('Alleen-apparaatgebruiker'), 'NL Device Only User');
bcm_same('Device Only User', narcissus_bc_usage_license_match('Gebruiker alleen apparaat'), 'NL Device Only User, andere caption');
bcm_same('Device Only User', narcissus_bc_usage_license_match('Device_Only_User'), 'enum-naam');
foreach (['Windows Group', 'Windows-groep', 'External User', 'Externe gebruiker', 'AAD Group', 'Agent', 'Application', 'Toepassing', ''] as $other) {
    bcm_same(null, narcissus_bc_usage_license_match($other), 'geen licentie: ' . $other);
}

bcm_same('x', narcissus_bc_usage_row_value(['user_name' => 'x'], 'User_Name'), 'kolomnaam hoofdletterongevoelig');
bcm_same('y', narcissus_bc_usage_row_value(['User_x0020_Name' => 'y'], 'User_Name'), 'kolomnaam met _x0020_');
bcm_same(null, narcissus_bc_usage_row_value(['Other' => 'z'], 'User_Name'), 'kolom ontbreekt');

/* ---------- Kleine Mímir-achtige set ---------- */
$licensed = narcissus_bc_usage_licensed_users([
    ['User_Security_ID' => 'a', 'User_Name' => 'KVT\\AALI', 'Full_Name' => 'A', 'State' => 'Geactiveerd', 'License_Type' => 'Volwaardige gebruiker'],
    ['User_Security_ID' => 'b', 'User_Name' => 'KVT\\BEP', 'Full_Name' => 'B', 'State' => 'Geactiveerd', 'License_Type' => 'Beperkte gebruiker'],
    ['User_Security_ID' => 'c', 'User_Name' => 'KVT\\UIT', 'Full_Name' => 'C', 'State' => 'Gedeactiveerd', 'License_Type' => 'Volwaardige gebruiker'],
    ['user_name' => 'KVT\\KLEIN', 'full_name' => 'D', 'state' => 'ENABLED', 'license_type' => 'Full_x0020_User'],
    ['User_Name' => 'KVT\\WIN', 'Full_Name' => 'E', 'State' => 'Geactiveerd', 'License_Type' => 'Windows-groep'],
]);
bcm_same(['KVT\\AALI', 'KVT\\BEP', 'KVT\\KLEIN'], array_keys($licensed), 'NL-captions, _x0020_ en kleine letters herkend');
bcm_same('Full User', $licensed['KVT\\AALI']['license'], 'licentie canoniek Engels opgeslagen');
bcm_same('Limited User', $licensed['KVT\\BEP']['license'], 'Beperkte gebruiker = Limited User');
bcm_same('D', $licensed['KVT\\KLEIN']['name'], 'Full_Name hoofdletterongevoelig');

/* ---------- Realistische fixture in Mímir-formaat (nl-NL) via nep-Mímir ---------- */
$fixture = bc_usage_fixture('2026-10-06');
$english = $fixture['users'];
$fixture['users'] = bc_usage_fixture_mimir_users($english, NARCISSUS_BC_USAGE_USERS_SELECT, 'nl');
bcm_same(199, count($fixture['users']), 'Mímir-fixture: 199 rijen');
bcm_same('Geactiveerd', $fixture['users'][0]['State'], 'fixture: NL State');
bcm_same('Volwaardige gebruiker', $fixture['users'][0]['License_Type'], 'fixture: NL License_Type');
bcm_assert(!isset($fixture['users'][0]['@odata.etag']), 'fixture: geprojecteerd zonder etag');

$path = bcm_temp_path();
$result = narcissus_bc_usage_refresh($today, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, bc_usage_fixture_transport($fixture), $path, $now);
bcm_assert($result['written'] && !$result['kept_previous'], 'nl-NL: geschreven');
bcm_same(160, $result['users'], 'nl-NL: 160 gelicentieerde gebruikers (zelfde als Engels)');
bcm_same(['ok', 'ok', 'ok', 'ok'], array_column($result['sources'], 'status'), 'nl-NL: alle bronnen ok');
$licenses = array_count_values(array_column(narcissus_bc_usage_read($path)['users'], 'license'));
ksort($licenses);
bcm_same(['Device Only User' => 1, 'Full User' => 157, 'Limited User' => 2], $licenses, 'nl-NL: licentietypes canoniek');

$diag = $result['users_diagnostics'];
bcm_same([199, 199, 163, 160], [$diag['rows'], $diag['named'], $diag['enabled'], $diag['licensed']], 'diagnose: tellingen');
bcm_same(['Geactiveerd' => 163, 'Gedeactiveerd' => 36], $diag['state_values'], 'diagnose: State-waarden');
bcm_same([], $diag['columns'], 'diagnose: geen kolomnamen als er gelicentieerden zijn');
$lines = narcissus_bc_usage_diagnostics_lines($diag);
bcm_same(
    ['users_rows=199 named=199 enabled=163 licensed=160 state_values=Geactiveerd:163,Gedeactiveerd:36 license_values=Volwaardige gebruiker:193,Toepassing:3,Beperkte gebruiker:2,Alleen apparaatgebruiker:1'],
    $lines,
    'diagnoseregel'
);
bcm_cleanup($path);

/* ---------- _xHHHH_-codering en afwijkende hoofdletters ---------- */
$fixture['users'] = bc_usage_fixture_mimir_users($english, NARCISSUS_BC_USAGE_USERS_SELECT, 'xhhhh');
$path = bcm_temp_path();
$result = narcissus_bc_usage_refresh($today, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, bc_usage_fixture_transport($fixture), $path, $now);
bcm_same(160, $result['users'], '_x0020_: 160 gelicentieerde gebruikers');
bcm_cleanup($path);

/* ---------- Niemand gelicentieerd: niet stil falen ---------- */
$fixture['users'] = array_map(static function (array $row): array {
    return ['UserName' => $row['User_Name'], 'Status' => 'Geactiveerd', 'Licentietype' => 'Volwaardige gebruiker', 'Authentication_Email' => $row['Authentication_Email']];
}, $english);
$path = bcm_temp_path();
$result = narcissus_bc_usage_refresh($today, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, bc_usage_fixture_transport($fixture), $path, $now);
bcm_assert($result['kept_previous'], 'licensed=0 bij >0 rijen: telt als onvolledig, vorige gegevens blijven');
bcm_same('geen_licenties', $result['sources'][0]['status'], 'Users-status geen_licenties');
bcm_assert(str_contains($result['sources'][0]['message'], '199 rijen'), 'melding noemt het aantal rijen');
bcm_same(['ok', 'ok', 'ok'], array_column(array_slice($result['sources'], 1), 'status'), 'UserTimeRegisters blijven ok');
$diag = $result['users_diagnostics'];
bcm_same([199, 0, 0, 0], [$diag['rows'], $diag['named'], $diag['enabled'], $diag['licensed']], 'diagnose bij verkeerde kolommen');
bcm_same(['UserName', 'Status', 'Licentietype', 'Authentication_Email'], $diag['columns'], 'diagnose: kolomnamen eerste rij');
$lines = narcissus_bc_usage_diagnostics_lines($diag);
bcm_same('users_rows=199 named=0 enabled=0 licensed=0 state_values=(ontbreekt):199 license_values=(ontbreekt):199', $lines[0], 'diagnoseregel bij 0');
bcm_same('users_columns=UserName,Status,Licentietype,Authentication_Email', $lines[1], 'kolomnamenregel');
$joined = implode("\n", $lines);
bcm_assert(!str_contains($joined, 'KVT\\') && !str_contains($joined, 'kvt.example'), 'diagnose bevat geen namen of e-mails');
bcm_same('geen_licenties', narcissus_bc_usage_read($path)['sources'][0]['status'], 'status staat in het databestand (melding op de tab)');
bcm_cleanup($path);

$fixture['users'] = [];
$path = bcm_temp_path();
$result = narcissus_bc_usage_refresh($today, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, bc_usage_fixture_transport($fixture), $path, $now);
bcm_assert($result['kept_previous'], 'Users zonder rijen: onvolledig');
bcm_same('geen_licenties', $result['sources'][0]['status'], 'Users zonder rijen: status');
bcm_same(['users_rows=0 named=0 enabled=0 licensed=0 state_values=- license_values=-'], narcissus_bc_usage_diagnostics_lines($result['users_diagnostics']), 'diagnose zonder rijen');
bcm_cleanup($path);

/* ---------- Diagnosewaarden veilig voor één regel ---------- */
bcm_same('a b c', narcissus_bc_usage_diag_value("a,b:c"), 'scheidingstekens weg');
bcm_same('(leeg)', narcissus_bc_usage_diag_value('  '), 'leeg');
bcm_same(41, mb_strlen(narcissus_bc_usage_diag_value(str_repeat('x', 80))), 'afgekapt op 40 + …');
$many = [];
for ($i = 0; $i < 15; $i++) {
    $many['v' . $i] = 1;
}
bcm_assert(str_ends_with(narcissus_bc_usage_diag_counts($many), '(overig):3'), 'maximaal 12 waarden, rest als overig');

echo "bc_usage_mimir_format_test OK ({$assertions} assertions)\n";
