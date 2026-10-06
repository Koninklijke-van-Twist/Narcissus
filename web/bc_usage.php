<?php

/**
 * BC Gebruik: hoeveel gebruiken BC-gebruikers met licentie Business Central?
 *
 * Bronnen (ODataV4 via Mímir, alleen lezen):
 * - Users (page 9800), één keer onder Company('Koninklijke van Twist'): licentietype en status.
 * - UserTimeRegisters (page 71), per bedrijf: minuten per gebruiker per dag.
 *   Minuten zijn inclusief idle-tijd en tellen alleen waar Register Time aan staat.
 *
 * Opslag: alleen aggregaten (minuten per gebruiker per dag in het venster) voor
 * gebruikers met State Enabled en een licentietype uit NARCISSUS_BC_USAGE_LICENSE_TYPES.
 * Het databestand staat in web/data/ achter een PHP-guard, zodat het nooit direct
 * via HTTP leesbaar is. Alles hier is alleen voor beheerders ($admins in auth.php).
 */

/**
 * Includes/requires
 */
require_once __DIR__ . '/narcissus_data.php';
require_once __DIR__ . '/mimir_client.php';

/**
 * Constants
 */

const NARCISSUS_BC_USAGE_WINDOW_WEEKS = 26;
const NARCISSUS_BC_USAGE_RECENT_DAYS = 30;
const NARCISSUS_BC_USAGE_LOW_ACTIVE_DAYS = 4;
const NARCISSUS_BC_USAGE_LOW_LAST_DAY_DAYS = 30;
const NARCISSUS_BC_USAGE_HEATMAP_SCALE = 'linear';
// Dagwaarden boven deze grens (meer dan 24:00, bv. een sessie die dagen open bleef of bedrijven die over
// elkaar heen tellen) worden alleen genegeerd bij het bepalen van het kleurplafond. In totalen, gemiddelden
// en de heatmap tellen ze gewoon mee; zo'n cel krijgt de maximale kleur.
const NARCISSUS_BC_USAGE_OUTLIER_MINUTES = 1440;
// Kleurplafond = dit percentiel (nearest rank) van alle dagwaarden t/m NARCISSUS_BC_USAGE_OUTLIER_MINUTES.
// Live (okt 2026): max 24:00, P99 23:13, P95 20:08. De staart tegen 24:00 is dicht, dus het maximum
// is vrijwel altijd de drempel zelf; P99 volgt de data en is ongevoelig voor één losse waarde.
const NARCISSUS_BC_USAGE_CEILING_PERCENTILE = 99;
const NARCISSUS_BC_USAGE_USERS_TABLE = 'Users';
const NARCISSUS_BC_USAGE_TIME_TABLE = 'UserTimeRegisters';
const NARCISSUS_BC_USAGE_USERS_COMPANY = 'Koninklijke van Twist';
const NARCISSUS_BC_USAGE_TIME_COMPANIES = ['Koninklijke van Twist', 'Hunter van Twist', 'KVT Gas'];
const NARCISSUS_BC_USAGE_STATE_ENABLED = 'Enabled';
const NARCISSUS_BC_USAGE_LICENSE_TYPES = ['Full User', 'Limited User', 'Device Only User'];
const NARCISSUS_BC_USAGE_USERS_SELECT = [
    'User_Security_ID',
    'User_Name',
    'Full_Name',
    'State',
    'License_Type',
    'Authentication_Email',
];
const NARCISSUS_BC_USAGE_TIME_SELECT = ['User_ID', 'Date', 'Minutes'];
const NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY = 14400;
const NARCISSUS_BC_USAGE_STALE_HOURS = 36;
const NARCISSUS_BC_USAGE_DATA_FILE = 'bc_gebruik.php';
const NARCISSUS_BC_USAGE_DATA_GUARD = "<?php http_response_code(403); exit; ?>\n";
const NARCISSUS_BC_USAGE_DATA_VERSION = 1;
const NARCISSUS_DUTCH_MONTHS = [
    'januari', 'februari', 'maart', 'april', 'mei', 'juni',
    'juli', 'augustus', 'september', 'oktober', 'november', 'december',
];

/**
 * Functies: beheerders
 */

function narcissus_session_email(): string
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) {
        return '';
    }

    return strtolower(trim((string) ($user['email'] ?? '')));
}

/**
 * Beheerders uit $admins in auth.php. Leeg of ontbrekend = niemand (fail-closed).
 *
 * @return list<string>
 */
function narcissus_admin_emails(?array $admins = null): array
{
    if ($admins === null) {
        $admins = (isset($GLOBALS['admins']) && is_array($GLOBALS['admins'])) ? $GLOBALS['admins'] : [];
    }

    $emails = [];
    foreach ($admins as $key => $value) {
        $email = is_int($key) ? (is_string($value) ? $value : '') : (string) $key;
        $email = strtolower(trim($email));
        if ($email !== '') {
            $emails[$email] = true;
        }
    }

    return array_keys($emails);
}

function narcissus_is_admin(?string $email = null, ?array $admins = null): bool
{
    $email = strtolower(trim($email ?? narcissus_session_email()));
    if ($email === '') {
        return false;
    }

    return in_array($email, narcissus_admin_emails($admins), true);
}

function narcissus_require_admin_json(): void
{
    if (narcissus_is_admin()) {
        return;
    }

    narcissus_json(['ok' => false, 'error' => 'Alleen voor beheerders.'], 403);
}

/**
 * Tabbalk. Niet-beheerders zien geen tabs (BC Gebruik is alleen voor beheerders).
 */
function narcissus_tabs_html(string $active, ?bool $isAdmin = null): string
{
    $isAdmin = $isAdmin ?? narcissus_is_admin();
    if (!$isAdmin) {
        return '';
    }

    $tabs = [
        'pagina' => ['label' => 'Pagina-activiteit', 'href' => 'index.php'],
        'bc-gebruik' => ['label' => 'BC Gebruik', 'href' => 'bc_gebruik.php'],
    ];

    $html = '<nav class="narc-tabs" aria-label="Onderdelen">';
    foreach ($tabs as $id => $tab) {
        $current = $id === $active;
        $html .= '<a class="narc-tab' . ($current ? ' is-active' : '') . '" href="' . narcissus_h($tab['href']) . '"'
            . ($current ? ' aria-current="page"' : '') . '>' . narcissus_h($tab['label']) . '</a>';
    }

    return $html . '</nav>';
}

/**
 * Functies: datum en tijd (leesbaar Nederlands, Europe/Amsterdam)
 */

function narcissus_format_dutch_date(?string $date): string
{
    $parsed = narcissus_parse_date((string) $date);
    if ($parsed === '') {
        return '—';
    }

    [$year, $month, $day] = array_map('intval', explode('-', $parsed));

    return $day . ' ' . NARCISSUS_DUTCH_MONTHS[$month - 1] . ' ' . $year;
}

function narcissus_format_dutch_datetime(?int $timestamp): string
{
    if ($timestamp === null || $timestamp <= 0) {
        return '—';
    }

    $moment = (new DateTimeImmutable('@' . $timestamp))->setTimezone(narcissus_timezone());

    return narcissus_format_dutch_date($moment->format('Y-m-d')) . ' om ' . $moment->format('H:i');
}

function narcissus_format_minutes_hhmm(int $minutes): string
{
    $minutes = max(0, $minutes);

    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function narcissus_bc_usage_is_outlier(int $minutes): bool
{
    return $minutes > NARCISSUS_BC_USAGE_OUTLIER_MINUTES;
}

/**
 * Percentiel volgens nearest rank (P100 = maximum). Lege lijst = 0.
 *
 * @param list<int> $values
 */
function narcissus_bc_usage_percentile(array $values, float $percentile): int
{
    if ($values === []) {
        return 0;
    }

    sort($values, SORT_NUMERIC);
    $rank = (int) ceil((max(0.0, min(100.0, $percentile)) / 100) * count($values));

    return (int) $values[max(0, min(count($values) - 1, $rank - 1))];
}

/**
 * Functies: venster
 */

function narcissus_bc_usage_today(?DateTimeImmutable $today = null): DateTimeImmutable
{
    if ($today === null) {
        return new DateTimeImmutable('today', narcissus_timezone());
    }

    return $today->setTimezone(narcissus_timezone())->setTime(0, 0, 0);
}

/**
 * Venster = NARCISSUS_BC_USAGE_WINDOW_WEEKS hele ISO-weken t/m de huidige week.
 *
 * @return array{from: string, to: string, today: string, recent_from: string, cols: int, rows: int}
 */
function narcissus_bc_usage_window(?DateTimeImmutable $today = null): array
{
    $day = narcissus_bc_usage_today($today);
    $range = narcissus_heatmap_date_range(NARCISSUS_BC_USAGE_WINDOW_WEEKS, 7, $day);

    return [
        'from' => $range['from'],
        'to' => $range['to'],
        'today' => $day->format('Y-m-d'),
        'recent_from' => $day->modify('-' . (NARCISSUS_BC_USAGE_RECENT_DAYS - 1) . ' days')->format('Y-m-d'),
        'cols' => $range['cols'],
        'rows' => $range['rows'],
    ];
}

/**
 * Functies: ophalen en aggregeren
 */

function narcissus_bc_usage_user_key(string $userName): string
{
    return strtoupper(trim($userName));
}

/**
 * Alleen State Enabled en een licentietype uit NARCISSUS_BC_USAGE_LICENSE_TYPES.
 * Bewaart bewust geen SID of e-mail: naam en licentietype zijn genoeg.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, array{user: string, name: string, license: string}>
 */
function narcissus_bc_usage_licensed_users(array $rows): array
{
    $users = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $userName = trim((string) ($row['User_Name'] ?? ''));
        $state = trim((string) ($row['State'] ?? ''));
        $license = trim((string) ($row['License_Type'] ?? ''));
        if ($userName === '' || strcasecmp($state, NARCISSUS_BC_USAGE_STATE_ENABLED) !== 0) {
            continue;
        }

        $licenseMatch = null;
        foreach (NARCISSUS_BC_USAGE_LICENSE_TYPES as $type) {
            if (strcasecmp($license, $type) === 0) {
                $licenseMatch = $type;
                break;
            }
        }
        if ($licenseMatch === null) {
            continue;
        }

        $fullName = trim((string) ($row['Full_Name'] ?? ''));
        $users[narcissus_bc_usage_user_key($userName)] = [
            'user' => $userName,
            'name' => $fullName !== '' ? $fullName : $userName,
            'license' => $licenseMatch,
        ];
    }

    uasort($users, static function (array $left, array $right): int {
        return strnatcasecmp($left['name'], $right['name']);
    });

    return $users;
}

/**
 * Telt minuten per gebruiker per dag op over alle bedrijven.
 * Join: UserTimeRegisters.User_ID = Users.User_Name (case-insensitive, getrimd).
 * Rijen van niet-gelicentieerde gebruikers en buiten het venster vallen weg.
 *
 * @param array<string, array{user: string, name: string, license: string}> $licensedUsers
 * @param list<list<array<string, mixed>>> $timeRowSets
 * @return list<array{user: string, name: string, license: string, days: array<string, int>}>
 */
function narcissus_bc_usage_aggregate(array $licensedUsers, array $timeRowSets, string $from, string $to): array
{
    $minutes = [];
    foreach ($timeRowSets as $rows) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = narcissus_bc_usage_user_key((string) ($row['User_ID'] ?? ''));
            if ($key === '' || !isset($licensedUsers[$key])) {
                continue;
            }

            $date = narcissus_parse_date(substr(trim((string) ($row['Date'] ?? '')), 0, 10));
            if ($date === '' || $date < $from || $date > $to) {
                continue;
            }

            $value = (int) round((float) ($row['Minutes'] ?? 0));
            if ($value <= 0) {
                continue;
            }

            $minutes[$key][$date] = ($minutes[$key][$date] ?? 0) + $value;
        }
    }

    $result = [];
    foreach ($licensedUsers as $key => $user) {
        $days = $minutes[$key] ?? [];
        ksort($days, SORT_STRING);
        $result[] = [
            'user' => $user['user'],
            'name' => $user['name'],
            'license' => $user['license'],
            'days' => $days,
        ];
    }

    return $result;
}

/**
 * @return array{source: string, table: string, company: string, status: string, message: string, rows: int}
 */
function narcissus_bc_usage_source_status(string $table, string $company, ?Throwable $error, int $rows): array
{
    $label = $table . ' (' . $company . ')';
    if ($error === null) {
        return [
            'source' => $label,
            'table' => $table,
            'company' => $company,
            'status' => 'ok',
            'message' => '',
            'rows' => $rows,
        ];
    }

    $status = 'fout';
    $message = $label . ' kon niet worden opgehaald: ' . $error->getMessage();
    if (!narcissus_mimir_enabled()) {
        $status = 'niet_ingesteld';
        $message = 'Mímir is niet ingesteld ($mimirApi in auth.php), dus ' . $label . ' is niet opgehaald.';
    } elseif ($error instanceof NarcissusMimirException && $error->httpStatus() === 404) {
        $status = 'niet_gepubliceerd';
        $message = $label . ' is niet gevonden. Waarschijnlijk is de webservice niet (meer) gepubliceerd in BC of kent Mímir de tabel nog niet.';
    }

    return [
        'source' => $label,
        'table' => $table,
        'company' => $company,
        'status' => $status,
        'message' => $message,
        'rows' => 0,
    ];
}

/**
 * Haalt beide bronnen op via Mímir. Een bron die faalt geeft een status, geen exception.
 *
 * @return array{
 *   users_ok: bool,
 *   time_ok_count: int,
 *   sources: list<array<string, mixed>>,
 *   users: list<array{user: string, name: string, license: string, days: array<string, int>}>,
 *   window_from: string,
 *   data_through: string
 * }
 */
function narcissus_bc_usage_fetch(?DateTimeImmutable $today = null, int $maxAgeSeconds = NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, ?callable $transport = null): array
{
    $window = narcissus_bc_usage_window($today);
    $sources = [];

    $userRows = [];
    $usersOk = false;
    try {
        $userRows = narcissus_mimir_query(
            NARCISSUS_BC_USAGE_USERS_COMPANY,
            NARCISSUS_BC_USAGE_USERS_TABLE,
            NARCISSUS_BC_USAGE_USERS_SELECT,
            null,
            $maxAgeSeconds,
            $transport
        );
        $usersOk = true;
        $sources[] = narcissus_bc_usage_source_status(NARCISSUS_BC_USAGE_USERS_TABLE, NARCISSUS_BC_USAGE_USERS_COMPANY, null, count($userRows));
    } catch (Throwable $error) {
        $sources[] = narcissus_bc_usage_source_status(NARCISSUS_BC_USAGE_USERS_TABLE, NARCISSUS_BC_USAGE_USERS_COMPANY, $error, 0);
    }

    // Edm.Date: Mímir zet dit om naar "Date ge 2026-04-06" (zonder quotes) en kan zo cache/gap-fill gebruiken.
    $timeFilter = ['and' => [['field' => 'Date', 'op' => 'ge', 'value' => $window['from']]]];
    $timeRowSets = [];
    foreach (NARCISSUS_BC_USAGE_TIME_COMPANIES as $company) {
        try {
            $rows = narcissus_mimir_query(
                $company,
                NARCISSUS_BC_USAGE_TIME_TABLE,
                NARCISSUS_BC_USAGE_TIME_SELECT,
                $timeFilter,
                $maxAgeSeconds,
                $transport
            );
            $timeRowSets[] = $rows;
            $sources[] = narcissus_bc_usage_source_status(NARCISSUS_BC_USAGE_TIME_TABLE, $company, null, count($rows));
        } catch (Throwable $error) {
            $sources[] = narcissus_bc_usage_source_status(NARCISSUS_BC_USAGE_TIME_TABLE, $company, $error, 0);
        }
    }

    $licensed = $usersOk ? narcissus_bc_usage_licensed_users($userRows) : [];

    return [
        'users_ok' => $usersOk,
        'time_ok_count' => count($timeRowSets),
        'sources' => $sources,
        'users' => $usersOk ? narcissus_bc_usage_aggregate($licensed, $timeRowSets, $window['from'], $window['today']) : [],
        'window_from' => $window['from'],
        'data_through' => $window['today'],
    ];
}

/**
 * Functies: opslag (web/data/, achter PHP-guard)
 */

function narcissus_bc_usage_data_dir(): string
{
    return __DIR__ . '/data';
}

function narcissus_bc_usage_data_path(): string
{
    return narcissus_bc_usage_data_dir() . '/' . NARCISSUS_BC_USAGE_DATA_FILE;
}

function narcissus_bc_usage_ensure_data_dir(string $dir): bool
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    // Tweede slot naast de PHP-guard: Apache serveert niets uit web/data/.
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        );
    }

    return true;
}

function narcissus_bc_usage_write(array $data, ?string $path = null): bool
{
    $path = $path ?? narcissus_bc_usage_data_path();
    if (!narcissus_bc_usage_ensure_data_dir(dirname($path))) {
        return false;
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, NARCISSUS_BC_USAGE_DATA_GUARD . $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function narcissus_bc_usage_read(?string $path = null): ?array
{
    $path = $path ?? narcissus_bc_usage_data_path();
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || !str_starts_with($raw, NARCISSUS_BC_USAGE_DATA_GUARD)) {
        return null;
    }

    $decoded = json_decode(substr($raw, strlen(NARCISSUS_BC_USAGE_DATA_GUARD)), true);
    if (!is_array($decoded) || (int) ($decoded['version'] ?? 0) !== NARCISSUS_BC_USAGE_DATA_VERSION) {
        return null;
    }
    if (!is_array($decoded['users'] ?? null)) {
        $decoded['users'] = [];
    }
    if (!is_array($decoded['sources'] ?? null)) {
        $decoded['sources'] = [];
    }

    return $decoded;
}

/**
 * Nightly: ophalen en wegschrijven.
 * - Users mislukt of alle UserTimeRegisters mislukt: vorige gegevens blijven staan, alleen de bronstatus wordt bijgewerkt.
 * - Eén of twee bedrijven mislukt: nieuwe gegevens van de rest, met de melding per bron.
 *
 * @return array{written: bool, kept_previous: bool, users: int, sources: list<array<string, mixed>>}
 */
function narcissus_bc_usage_refresh(
    ?DateTimeImmutable $today = null,
    int $maxAgeSeconds = NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY,
    ?callable $transport = null,
    ?string $path = null,
    ?int $now = null
): array {
    $now = $now ?? time();
    $fetched = narcissus_bc_usage_fetch($today, $maxAgeSeconds, $transport);
    $previous = narcissus_bc_usage_read($path);
    $complete = $fetched['users_ok'] && $fetched['time_ok_count'] > 0;

    if ($complete) {
        $data = [
            'version' => NARCISSUS_BC_USAGE_DATA_VERSION,
            'generated_at' => $now,
            'attempted_at' => $now,
            'window_from' => $fetched['window_from'],
            'data_through' => $fetched['data_through'],
            'sources' => $fetched['sources'],
            'users' => $fetched['users'],
        ];
    } else {
        $data = $previous ?? [
            'version' => NARCISSUS_BC_USAGE_DATA_VERSION,
            'generated_at' => 0,
            'window_from' => $fetched['window_from'],
            'data_through' => '',
            'users' => [],
        ];
        $data['attempted_at'] = $now;
        $data['sources'] = $fetched['sources'];
    }

    $written = narcissus_bc_usage_write($data, $path);

    return [
        'written' => $written,
        'kept_previous' => !$complete,
        'users' => count($data['users'] ?? []),
        'sources' => $fetched['sources'],
    ];
}

/**
 * Functies: weergave
 */

/**
 * @return array<string, mixed>
 */
function narcissus_bc_usage_summary(?array $data, ?DateTimeImmutable $today = null, ?int $now = null): array
{
    $window = narcissus_bc_usage_window($today);
    $now = $now ?? time();
    $lowCutoff = narcissus_bc_usage_today($today)
        ->modify('-' . NARCISSUS_BC_USAGE_LOW_LAST_DAY_DAYS . ' days')
        ->format('Y-m-d');
    $todayTs = narcissus_bc_usage_today($today)->getTimestamp();

    $rows = [];
    $ceilingValues = [];
    $lowCount = 0;
    foreach (($data['users'] ?? []) as $user) {
        if (!is_array($user)) {
            continue;
        }

        $days = is_array($user['days'] ?? null) ? $user['days'] : [];
        $activeWindow = 0;
        $active30 = 0;
        $total = 0;
        $lastDay = null;
        foreach ($days as $date => $value) {
            $date = (string) $date;
            $value = (int) $value;
            if ($value <= 0 || $date < $window['from'] || $date > $window['today']) {
                continue;
            }

            $activeWindow++;
            $total += $value;
            if ($date >= $window['recent_from']) {
                $active30++;
            }
            if ($lastDay === null || $date > $lastDay) {
                $lastDay = $date;
            }
            // Alleen voor het kleurplafond: uitschieters boven NARCISSUS_BC_USAGE_OUTLIER_MINUTES tellen niet mee.
            if (!narcissus_bc_usage_is_outlier($value)) {
                $ceilingValues[] = $value;
            }
        }

        $daysSinceLast = null;
        if ($lastDay !== null) {
            $lastTs = (new DateTimeImmutable($lastDay . ' 00:00:00', narcissus_timezone()))->getTimestamp();
            $daysSinceLast = (int) round(($todayTs - $lastTs) / 86400);
        }

        $reasons = [];
        if ($active30 < NARCISSUS_BC_USAGE_LOW_ACTIVE_DAYS) {
            $reasons[] = 'minder dan ' . NARCISSUS_BC_USAGE_LOW_ACTIVE_DAYS . ' actieve dagen in ' . NARCISSUS_BC_USAGE_RECENT_DAYS . ' dagen';
        }
        if ($lastDay === null) {
            $reasons[] = 'geen registratie in het venster';
        } elseif ($lastDay < $lowCutoff) {
            $reasons[] = 'laatste registratie meer dan ' . NARCISSUS_BC_USAGE_LOW_LAST_DAY_DAYS . ' dagen geleden';
        }
        if ($reasons !== []) {
            $lowCount++;
        }

        $avg = $activeWindow > 0 ? (int) round($total / $activeWindow) : 0;
        $rows[] = [
            'user' => (string) ($user['user'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'license' => (string) ($user['license'] ?? ''),
            'active_30' => $active30,
            'active_window' => $activeWindow,
            'total_minutes' => $total,
            'total_hours' => round($total / 60, 1),
            'avg_minutes' => $avg,
            'avg_label' => narcissus_format_minutes_hhmm($avg),
            'last_day' => $lastDay,
            'last_day_label' => $lastDay !== null ? narcissus_format_dutch_date($lastDay) : 'Niet in het venster',
            'days_since_last' => $daysSinceLast,
            'low' => $reasons !== [],
            'low_reasons' => $reasons,
        ];
    }

    $ceiling = narcissus_bc_usage_percentile($ceilingValues, NARCISSUS_BC_USAGE_CEILING_PERCENTILE);
    $generatedAt = (int) ($data['generated_at'] ?? 0);
    $attemptedAt = (int) ($data['attempted_at'] ?? 0);
    $sources = [];
    foreach (($data['sources'] ?? []) as $source) {
        if (is_array($source)) {
            $sources[] = [
                'source' => (string) ($source['source'] ?? ''),
                'status' => (string) ($source['status'] ?? ''),
                'message' => (string) ($source['message'] ?? ''),
            ];
        }
    }

    return [
        'has_data' => $data !== null && $generatedAt > 0,
        'generated_at_label' => narcissus_format_dutch_datetime($generatedAt),
        'attempted_at_label' => narcissus_format_dutch_datetime($attemptedAt),
        'stale' => $generatedAt > 0 && ($now - $generatedAt) > NARCISSUS_BC_USAGE_STALE_HOURS * 3600,
        'window_from' => $window['from'],
        'window_today' => $window['today'],
        'window_label' => narcissus_format_dutch_date($window['from']) . ' t/m ' . narcissus_format_dutch_date($window['today']),
        'recent_label' => narcissus_format_dutch_date($window['recent_from']) . ' t/m ' . narcissus_format_dutch_date($window['today']),
        'ceiling_minutes' => $ceiling,
        'ceiling_label' => narcissus_format_minutes_hhmm($ceiling),
        'ceiling_percentile' => NARCISSUS_BC_USAGE_CEILING_PERCENTILE,
        'outlier_minutes' => NARCISSUS_BC_USAGE_OUTLIER_MINUTES,
        'outlier_label' => narcissus_format_minutes_hhmm(NARCISSUS_BC_USAGE_OUTLIER_MINUTES),
        'user_count' => count($rows),
        'low_count' => $lowCount,
        'thresholds' => [
            'low_active_days' => NARCISSUS_BC_USAGE_LOW_ACTIVE_DAYS,
            'recent_days' => NARCISSUS_BC_USAGE_RECENT_DAYS,
            'low_last_day_days' => NARCISSUS_BC_USAGE_LOW_LAST_DAY_DAYS,
            'window_weeks' => NARCISSUS_BC_USAGE_WINDOW_WEEKS,
        ],
        'sources' => $sources,
        'users' => $rows,
    ];
}

/**
 * Dagen voor de heatmap van één gebruiker (weekdag × week, hele venster).
 *
 * @return array{user: string, name: string, days: list<array{date: string, count: int, future: bool, out_of_range: bool}>}|null
 */
function narcissus_bc_usage_user_heatmap(?array $data, string $userName, ?DateTimeImmutable $today = null): ?array
{
    $key = narcissus_bc_usage_user_key($userName);
    if ($key === '') {
        return null;
    }

    $match = null;
    foreach (($data['users'] ?? []) as $user) {
        if (is_array($user) && narcissus_bc_usage_user_key((string) ($user['user'] ?? '')) === $key) {
            $match = $user;
            break;
        }
    }
    if ($match === null) {
        return null;
    }

    $window = narcissus_bc_usage_window($today);
    $minutes = is_array($match['days'] ?? null) ? $match['days'] : [];
    $days = [];
    foreach (narcissus_day_labels($window['from'], $window['to']) as $date) {
        $future = $date > $window['today'];
        $days[] = [
            'date' => $date,
            'count' => $future ? 0 : max(0, (int) ($minutes[$date] ?? 0)),
            'future' => $future,
            'out_of_range' => false,
        ];
    }

    return [
        'user' => (string) ($match['user'] ?? ''),
        'name' => (string) ($match['name'] ?? ''),
        'days' => $days,
    ];
}
