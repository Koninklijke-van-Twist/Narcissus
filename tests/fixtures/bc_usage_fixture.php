<?php

/**
 * Realistische, synthetische fixture voor BC Gebruik (geen echte personen).
 *
 * Verhoudingen naar de live-situatie van oktober 2026: ~199 rijen in Users,
 * 160 gelicentieerd (157 Full User, 2 Limited User, 1 Device Only User),
 * 36 Disabled en 3 Application-gebruikers. UserTimeRegisters over 6 maanden
 * voor KVT, HVT en KVT Gas, met idle-uitschieters boven 24 uur en
 * gebruikers zonder Register Time (geen rijen).
 *
 * @return array{
 *   users: list<array<string, mixed>>,
 *   time: array<string, list<array<string, mixed>>>,
 *   profiles: array<string, string>,
 *   from: string,
 *   today: string
 * }
 */
function bc_usage_fixture(string $today = '2026-10-06'): array
{
    mt_srand(20261006);

    $first = ['Anna', 'Bram', 'Cor', 'Daan', 'Eva', 'Femke', 'Gert', 'Hanna', 'Ilse', 'Jan', 'Kees', 'Lotte', 'Mark', 'Noor', 'Olaf', 'Petra', 'Ruud', 'Sanne', 'Tim', 'Wim'];
    $last = ['de Vries', 'Jansen', 'Bakker', 'Visser', 'Smit', 'Meijer', 'de Boer', 'Mulder', 'de Groot', 'Bos', 'Vos', 'Peters', 'Hendriks', 'van Dijk', 'Dekker'];

    $zone = new DateTimeZone('Europe/Amsterdam');
    $todayDate = new DateTimeImmutable($today, $zone);
    $from = $todayDate->modify('-' . ((int) $todayDate->format('N') - 1) . ' days')->modify('-175 days');

    $users = [];
    $profiles = [];
    $index = 0;
    $add = static function (string $state, string $license, string $profile) use (&$users, &$profiles, &$index, $first, $last): string {
        $index++;
        $userName = sprintf('KVT\\USER%03d', $index);
        $users[] = [
            '@odata.etag' => 'W/"fixture-' . $index . '"',
            'User_Security_ID' => sprintf('%08x-0000-4000-8000-%012d', $index, $index),
            'User_Name' => $userName,
            'Full_Name' => $first[$index % count($first)] . ' ' . $last[$index % count($last)] . ' ' . $index,
            'State' => $state,
            'License_Type' => $license,
            'Authentication_Email' => sprintf('user%03d@kvt.example', $index),
        ];
        $profiles[$userName] = $profile;

        return $userName;
    };

    // 157 Full User: verdeling zwaar / normaal / deeltijd / sporadisch / gestopt / zonder Register Time.
    $fullProfiles = array_merge(
        array_fill(0, 40, 'heavy'),
        array_fill(0, 55, 'normal'),
        array_fill(0, 20, 'parttime'),
        array_fill(0, 10, 'sporadic'),
        array_fill(0, 8, 'stopped'),
        array_fill(0, 24, 'none')
    );
    foreach ($fullProfiles as $profile) {
        $add('Enabled', 'Full User', $profile);
    }
    $add('Enabled', 'Limited User', 'parttime');
    $add('Enabled', 'Limited User', 'sporadic');
    $add('Enabled', 'Device Only User', 'normal');
    for ($i = 0; $i < 3; $i++) {
        $add('Enabled', 'Application', 'heavy');
    }
    for ($i = 0; $i < 36; $i++) {
        $add('Disabled', 'Full User', $i < 10 ? 'normal' : 'none');
    }

    $time = ['Koninklijke van Twist' => [], 'Hunter van Twist' => [], 'KVT Gas' => []];
    $stopDate = $todayDate->modify('-45 days')->format('Y-m-d');
    foreach ($profiles as $userName => $profile) {
        if ($profile === 'none') {
            continue;
        }
        $number = (int) substr($userName, -3);
        $hvt = $number % 6 === 0;
        $gas = $number % 25 === 0;
        $cursor = $from->modify('-14 days'); // ook rijen vóór het venster
        while ($cursor <= $todayDate) {
            $date = $cursor->format('Y-m-d');
            $weekday = (int) $cursor->format('N');
            $cursor = $cursor->modify('+1 day');

            $chance = [
                'heavy' => 0.92,
                'normal' => 0.8,
                'parttime' => 0.45,
                'sporadic' => 0.05,
                'stopped' => 0.75,
            ][$profile];
            if ($weekday >= 6) {
                $chance *= 0.06;
            }
            if ($profile === 'stopped' && $date > $stopDate) {
                $chance = 0;
            }
            if (mt_rand() / mt_getrandmax() > $chance) {
                continue;
            }

            $base = [
                'heavy' => [360, 600],
                'normal' => [180, 480],
                'parttime' => [60, 300],
                'sporadic' => [5, 90],
                'stopped' => [120, 420],
            ][$profile];
            $minutes = mt_rand($base[0], $base[1]);
            if ($profile === 'heavy' && mt_rand(1, 60) === 1) {
                $minutes = mt_rand(1500, 3150); // sessie over meerdere dagen open (idle)
            }

            $userId = $number % 7 === 0 ? ' ' . strtolower($userName) . ' ' : $userName;
            $time['Koninklijke van Twist'][] = ['@odata.etag' => 'W/"x"', 'User_ID' => $userId, 'Date' => $date, 'Minutes' => $minutes];
            if ($hvt && mt_rand(1, 3) === 1) {
                $time['Hunter van Twist'][] = ['@odata.etag' => 'W/"x"', 'User_ID' => $userName, 'Date' => $date, 'Minutes' => mt_rand(10, 120)];
            }
            if ($gas && mt_rand(1, 4) === 1) {
                $time['KVT Gas'][] = ['@odata.etag' => 'W/"x"', 'User_ID' => $userName, 'Date' => $date, 'Minutes' => mt_rand(5, 60)];
            }
        }
    }

    // Onbekende gebruiker (bv. verwijderd) en een rij met 0 minuten.
    $time['Koninklijke van Twist'][] = ['User_ID' => 'KVT\\ONBEKEND', 'Date' => $today, 'Minutes' => 300];
    $time['Hunter van Twist'][] = ['User_ID' => 'KVT\\USER001', 'Date' => $today, 'Minutes' => 0];

    return [
        'users' => $users,
        'time' => $time,
        'profiles' => $profiles,
        'from' => $from->format('Y-m-d'),
        'today' => $today,
    ];
}

/**
 * Nep-Mímir: beantwoordt query.php met de fixture en past het Date-filter toe.
 *
 * @param array<string, int> $statusByTable bv. ['UserTimeRegisters|KVT Gas' => 404]
 * @param list<array<string, mixed>> $requests ontvangt elk verzoek (body)
 */
function bc_usage_fixture_transport(array $fixture, array $statusByTable = [], ?array &$requests = null): callable
{
    $requests = [];

    return static function (string $url, string $apiKey, string $payload) use ($fixture, $statusByTable, &$requests): array {
        $body = json_decode($payload, true);
        $requests[] = ['url' => $url, 'api_key' => $apiKey, 'body' => $body];
        $table = (string) ($body['table'] ?? '');
        $company = (string) ($body['company'] ?? '');
        $status = $statusByTable[$table . '|' . $company] ?? $statusByTable[$table] ?? 200;
        if ($status === 404) {
            return ['status' => 404, 'body' => json_encode(['error' => 'Onbekende tabel: ' . $table])];
        }
        if ($status === 4040) {
            return ['status' => 404, 'body' => ''];
        }
        if ($status !== 200) {
            return ['status' => $status, 'body' => json_encode(['error' => 'Business Central gaf HTTP 500'])];
        }

        if ($table === 'Users') {
            $rows = $fixture['users'];
        } else {
            $rows = $fixture['time'][$company] ?? [];
            $min = '';
            foreach (($body['filter']['and'] ?? []) as $leaf) {
                if (($leaf['field'] ?? '') === 'Date' && ($leaf['op'] ?? '') === 'ge') {
                    $min = (string) $leaf['value'];
                }
            }
            if ($min !== '') {
                $rows = array_values(array_filter($rows, static function (array $row) use ($min): bool {
                    return (string) $row['Date'] >= $min;
                }));
            }
        }

        return ['status' => 200, 'body' => json_encode(['value' => $rows, 'meta' => ['from_cache' => 0]])];
    };
}

/**
 * Mímir-formaat van Users, zoals live gevonden (okt 2026):
 * - Mímir vraagt BC op met Accept-Language: nl-NL, dus BC geeft Nederlandse captions voor optievelden:
 *   State 'Geactiveerd'/'Gedeactiveerd', License_Type 'Volwaardige gebruiker' (live gecontroleerd).
 *   'Beperkte gebruiker' en 'Alleen apparaatgebruiker' zijn de verwachte NL-captions (live geen rijen).
 * - Users zonder filter: Mímir haalt alle kolommen uit BC en projecteert daarna op de gevraagde select
 *   (mimir_project_row), dus '@odata.etag' en niet-gevraagde kolommen vallen weg.
 *
 * @param 'nl'|'xhhhh' $variant 'xhhhh' = OData-naamcodering (Full_x0020_User) met afwijkende hoofdletters.
 * @param list<string> $select
 * @return list<array<string, mixed>>
 */
function bc_usage_fixture_mimir_users(array $users, array $select, string $variant = 'nl'): array
{
    $maps = [
        'nl' => [
            'State' => ['Enabled' => 'Geactiveerd', 'Disabled' => 'Gedeactiveerd'],
            'License_Type' => [
                'Full User' => 'Volwaardige gebruiker',
                'Limited User' => 'Beperkte gebruiker',
                'Device Only User' => 'Alleen apparaatgebruiker',
                'Application' => 'Toepassing',
            ],
        ],
        'xhhhh' => [
            'State' => ['Enabled' => 'ENABLED', 'Disabled' => 'disabled'],
            'License_Type' => [
                'Full User' => 'Full_x0020_User',
                'Limited User' => 'limited_x0020_user',
                'Device Only User' => 'Device_x0020_Only_x0020_User',
                'Application' => 'Application',
            ],
        ],
    ][$variant];

    $out = [];
    foreach ($users as $row) {
        foreach ($maps as $field => $map) {
            $row[$field] = $map[$row[$field]] ?? $row[$field];
        }
        $projected = [];
        foreach ($select as $column) {
            if (array_key_exists($column, $row)) {
                $projected[$column] = $row[$column];
            }
        }
        $out[] = $projected;
    }

    return $out;
}
