<?php

/**
 * Integratietest: alleen beheerders zien de tab en krijgen data.
 * Draait php -S op een tijdelijke kopie van web/ met een eigen test-auth.php,
 * zodat een echte web/auth.php nooit wordt aangeraakt.
 */

require_once dirname(__DIR__) . '/web/bc_usage.php';
require_once __DIR__ . '/fixtures/bc_usage_fixture.php';

function access_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function access_copy_web(string $source, string $target): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $top = explode('/', str_replace('\\', '/', $relative))[0];
        if (in_array($top, ['data', 'cache', 'auth.php'], true) || str_contains($relative, 'analytics.sqlite')) {
            continue;
        }
        $destination = $target . '/' . $relative;
        if ($item->isDir()) {
            @mkdir($destination, 0775, true);
        } else {
            copy($item->getPathname(), $destination);
        }
    }
}

function access_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => $body];
}

function access_remove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

$root = sys_get_temp_dir() . '/narcissus-access-' . bin2hex(random_bytes(4));
$web = $root . '/web';
mkdir($web, 0775, true);
access_copy_web(dirname(__DIR__) . '/web', $web);

// Data uit de realistische fixture, op de plek waar de app hem leest.
$GLOBALS['mimirApi'] = 'mimir_testsleutel';
$fixture = bc_usage_fixture((new DateTimeImmutable('today', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d'));
narcissus_bc_usage_refresh(null, NARCISSUS_BC_USAGE_MAX_AGE_NIGHTLY, bc_usage_fixture_transport($fixture), $web . '/data/' . NARCISSUS_BC_USAGE_DATA_FILE);

$writeAuth = static function (string $email, array $admins) use ($web): void {
    file_put_contents($web . '/auth.php', "<?php\n\$allowedUsers = " . var_export([$email], true) . ";\n\$admins = " . var_export($admins, true) . ";\n");
};

$port = 18000 + random_int(0, 999);
$base = 'http://127.0.0.1:' . $port;
$process = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $web],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
access_assert(is_resource($process), 'php -S gestart');

try {
    $writeAuth('collega@kvt.nl', ['tim@kvt.nl']);
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port) !== false) {
            break;
        }
        usleep(100000);
    }

    // Niet-beheerder
    $index = access_get($base . '/index.php');
    access_assert($index['status'] === 200, 'index.php 200 voor gewone gebruiker');
    access_assert(!str_contains($index['body'], 'bc_gebruik.php') && !str_contains($index['body'], 'BC Gebruik'), 'gewone gebruiker ziet geen BC Gebruik-tab');
    access_assert(access_get($base . '/bc_gebruik.php')['status'] === 403, 'bc_gebruik.php 403');
    $api = access_get($base . '/api.php?action=bcgebruik');
    access_assert($api['status'] === 403 && !str_contains($api['body'], 'USER0'), 'api bcgebruik 403 zonder data');
    access_assert(access_get($base . '/api.php?action=bcgebruik_heatmap&user=KVT%5CUSER001')['status'] === 403, 'api heatmap 403');

    // Databestand nooit direct leesbaar
    $direct = access_get($base . '/data/' . NARCISSUS_BC_USAGE_DATA_FILE);
    access_assert($direct['status'] === 403 && !str_contains($direct['body'], 'USER0'), 'databestand direct: 403 zonder inhoud');

    // Lege $admins = niemand
    $writeAuth('tim@kvt.nl', []);
    access_assert(access_get($base . '/bc_gebruik.php')['status'] === 403, 'lege $admins: 403');

    // Beheerder
    $writeAuth('tim@kvt.nl', ['Tim@KVT.nl']);
    $index = access_get($base . '/index.php');
    access_assert(str_contains($index['body'], 'href="bc_gebruik.php"'), 'beheerder ziet BC Gebruik-tab');
    $page = access_get($base . '/bc_gebruik.php');
    access_assert($page['status'] === 200 && str_contains($page['body'], 'inclusief idle-tijd') && str_contains($page['body'], 'Register Time'), 'beheerder: pagina met idle/Register Time-uitleg');
    access_assert(!preg_match('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', strip_tags(explode('<script', $page['body'])[0])), 'geen ruwe ISO-tijden in de pagina');
    $api = access_get($base . '/api.php?action=bcgebruik');
    $payload = json_decode($api['body'], true);
    access_assert($api['status'] === 200 && ($payload['summary']['user_count'] ?? 0) === 160, 'beheerder: summary met 160 gebruikers');
    $heat = json_decode(access_get($base . '/api.php?action=bcgebruik_heatmap&user=KVT%5CUSER001')['body'], true);
    access_assert(count($heat['heatmap']['days'] ?? []) === 182, 'beheerder: heatmap 182 dagen');
    access_assert(access_get($base . '/api.php?action=bcgebruik_heatmap&user=KVT%5CNIEMAND')['status'] === 404, 'onbekende gebruiker 404');
} finally {
    proc_terminate($process);
    proc_close($process);
    access_remove($root);
}

echo "bc_usage_access_test OK\n";
