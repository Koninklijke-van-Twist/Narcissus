<?php

/**
 * Constants
 */

const NARCISSUS_TIMEZONE = 'Europe/Amsterdam';

/**
 * Functies
 */

function narcissus_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function narcissus_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function narcissus_timezone(): DateTimeZone
{
    return new DateTimeZone(NARCISSUS_TIMEZONE);
}

function narcissus_is_localhost_request(): bool
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = strtolower((string) preg_replace('/:\d+$/', '', $host));
    $localIps = ['127.0.0.1', '::1'];
    $localHosts = ['localhost', '127.0.0.1', '::1'];

    return in_array($remote, $localIps, true) && in_array($host, $localHosts, true);
}

function narcissus_uses_web_folder(): bool
{
    if (narcissus_is_localhost_request()) {
        return true;
    }

    return strcasecmp(basename(__DIR__), 'web') === 0;
}

function narcissus_analytics_glob_pattern(): string
{
    $appRoot = str_replace('\\', '/', __DIR__);

    if (narcissus_uses_web_folder()) {
        $htdocs = dirname($appRoot, 2);
        return $htdocs . '/*/web/analytics/analytics.sqlite';
    }

    $sitesRoot = dirname($appRoot);
    return $sitesRoot . '/*/analytics/analytics.sqlite';
}

function narcissus_page_name_from_sqlite_path(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $analyticsDir = dirname($normalized);
    $parentDir = dirname($analyticsDir);

    if (strcasecmp(basename($parentDir), 'web') === 0) {
        return basename(dirname($parentDir));
    }

    return basename($parentDir);
}

function narcissus_page_id(string $name): string
{
    return strtolower(trim($name));
}

/**
 * @return list<array{id: string, name: string, path: string}>
 */
function narcissus_discover_pages(): array
{
    $pattern = narcissus_analytics_glob_pattern();
    $files = glob($pattern) ?: [];
    $pages = [];
    $seen = [];

    foreach ($files as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }

        $name = narcissus_page_name_from_sqlite_path($file);
        $id = narcissus_page_id($name);
        if ($id === '' || isset($seen[$id])) {
            continue;
        }

        $seen[$id] = true;
        $pages[] = [
            'id' => $id,
            'name' => $name,
            'path' => $file,
        ];
    }

    usort($pages, static function (array $left, array $right): int {
        return strnatcasecmp((string) $left['name'], (string) $right['name']);
    });

    return $pages;
}

function narcissus_page_by_id(string $id, ?array $pages = null): ?array
{
    $id = narcissus_page_id($id);
    if ($id === '') {
        return null;
    }

    foreach ($pages ?? narcissus_discover_pages() as $page) {
        if ((string) ($page['id'] ?? '') === $id) {
            return $page;
        }
    }

    return null;
}

function narcissus_pdo(string $path): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (defined('PDO::SQLITE_OPEN_READONLY')) {
        $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
    }

    $pdo = new PDO('sqlite:' . $path, null, null, $options);
    try {
        $pdo->exec('PRAGMA busy_timeout = 1000');
    } catch (Throwable) {
        // Best-effort: sommige readonly-verbindingen weigeren PRAGMA.
    }

    return $pdo;
}

function narcissus_parse_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value, narcissus_timezone());
    if (!$date instanceof DateTimeImmutable) {
        return '';
    }

    $errors = DateTimeImmutable::getLastErrors();
    if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return '';
    }

    return $date->format('Y-m-d');
}

function narcissus_default_date_to(): string
{
    return (new DateTimeImmutable('today', narcissus_timezone()))->format('Y-m-d');
}

function narcissus_default_date_from(): string
{
    return (new DateTimeImmutable('today', narcissus_timezone()))
        ->modify('-1 month')
        ->format('Y-m-d');
}

function narcissus_day_bounds(string $date, bool $endOfDay = false): int
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date, narcissus_timezone());
    if (!$parsed instanceof DateTimeImmutable) {
        $parsed = new DateTimeImmutable('today', narcissus_timezone());
    }

    $parsed = $endOfDay ? $parsed->setTime(23, 59, 59) : $parsed->setTime(0, 0, 0);
    return $parsed->getTimestamp();
}

/**
 * @return list<string>
 */
function narcissus_day_labels(string $from, string $to): array
{
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $from, narcissus_timezone())
        ?: new DateTimeImmutable('today', narcissus_timezone());
    $end = DateTimeImmutable::createFromFormat('Y-m-d', $to, narcissus_timezone())
        ?: new DateTimeImmutable('today', narcissus_timezone());
    $start = $start->setTime(0, 0, 0);
    $end = $end->setTime(0, 0, 0);

    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }

    $labels = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $labels[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    return $labels;
}

function narcissus_relative_timestamp(int $days): int
{
    return time() - ($days * 86400);
}

function narcissus_format_datetime(?int $timestamp): string
{
    if ($timestamp === null || $timestamp <= 0) {
        return '—';
    }

    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(narcissus_timezone())
        ->format('d-m-Y H:i');
}

/**
 * @param list<array{id: string, name: string, path: string}> $pages
 * @return array{from: string, to: string, labels: list<string>, series: list<array{id: string, name: string, data: list<int>}>}
 */
function narcissus_chart_data(array $pages, string $from, string $to): array
{
    $labels = narcissus_day_labels($from, $to);
    $fromTs = narcissus_day_bounds($from, false);
    $toTs = narcissus_day_bounds($to, true);
    $series = [];

    foreach ($pages as $page) {
        $counts = array_fill_keys($labels, 0);
        try {
            $pdo = narcissus_pdo((string) $page['path']);
            $statement = $pdo->prepare(
                'SELECT visited_at
                 FROM visits
                 WHERE visited_at >= :from_ts AND visited_at <= :to_ts'
            );
            $statement->execute([
                ':from_ts' => $fromTs,
                ':to_ts' => $toTs,
            ]);

            $tz = narcissus_timezone();
            while ($row = $statement->fetch()) {
                $timestamp = (int) ($row['visited_at'] ?? 0);
                if ($timestamp <= 0) {
                    continue;
                }
                $day = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format('Y-m-d');
                if (isset($counts[$day])) {
                    $counts[$day]++;
                }
            }
        } catch (Throwable) {
            $counts = array_fill_keys($labels, 0);
        }

        $series[] = [
            'id' => (string) $page['id'],
            'name' => (string) $page['name'],
            'data' => array_map('intval', array_values($counts)),
        ];
    }

    return [
        'from' => $from,
        'to' => $to,
        'labels' => $labels,
        'series' => $series,
    ];
}

/**
 * @return list<array{email: string, week: int, month: int, year?: int, last_seen: int, last_seen_label: string}>
 */
function narcissus_user_stats(string $path, string $query = '', int $limit = 10, bool $includeYear = false): array
{
    $weekFrom = narcissus_relative_timestamp(7);
    $monthFrom = narcissus_relative_timestamp(30);
    $yearFrom = narcissus_relative_timestamp(365);
    $needle = strtolower(trim($query));

    $selectYear = $includeYear
        ? 'SUM(CASE WHEN visited_at >= :year_from THEN 1 ELSE 0 END) AS year_count,'
        : '';

    $sql = 'SELECT user_email,
                   SUM(CASE WHEN visited_at >= :week_from THEN 1 ELSE 0 END) AS week_count,
                   SUM(CASE WHEN visited_at >= :month_from THEN 1 ELSE 0 END) AS month_count,
                   ' . $selectYear . '
                   MAX(visited_at) AS last_seen
            FROM visits';

    $params = [
        ':week_from' => $weekFrom,
        ':month_from' => $monthFrom,
    ];
    if ($includeYear) {
        $params[':year_from'] = $yearFrom;
    }

    if ($needle !== '') {
        $sql .= ' WHERE lower(user_email) LIKE :query';
        $params[':query'] = '%' . $needle . '%';
    }

    $limit = max(1, min(50, $limit));
    $sql .= ' GROUP BY user_email
              ORDER BY month_count DESC, week_count DESC, last_seen DESC
              LIMIT ' . $limit;

    try {
        $pdo = narcissus_pdo($path);
        $statement = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === ':query') {
                $statement->bindValue($key, (string) $value, PDO::PARAM_STR);
                continue;
            }
            $statement->bindValue($key, (int) $value, PDO::PARAM_INT);
        }
        $statement->execute();
        $rows = $statement->fetchAll();
    } catch (Throwable) {
        return [];
    }

    $users = [];
    foreach ($rows as $row) {
        $email = strtolower(trim((string) ($row['user_email'] ?? '')));
        if ($email === '') {
            continue;
        }

        $lastSeen = (int) ($row['last_seen'] ?? 0);
        $user = [
            'email' => $email,
            'week' => (int) ($row['week_count'] ?? 0),
            'month' => (int) ($row['month_count'] ?? 0),
            'last_seen' => $lastSeen,
            'last_seen_label' => narcissus_format_datetime($lastSeen),
        ];
        if ($includeYear) {
            $user['year'] = (int) ($row['year_count'] ?? 0);
        }
        $users[] = $user;
    }

    return $users;
}

/**
 * @return list<array{email: string, week: int, month: int, last_seen: int, last_seen_label: string}>
 */
function narcissus_top_users(string $path, int $limit = 10): array
{
    return narcissus_user_stats($path, '', $limit, false);
}

/**
 * @return list<array{email: string, week: int, month: int, year: int, last_seen: int, last_seen_label: string}>
 */
function narcissus_search_users(string $path, string $query, int $limit = 10): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    return narcissus_user_stats($path, $query, $limit, true);
}
