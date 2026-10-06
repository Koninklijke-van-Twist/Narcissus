<?php

/**
 * Kleine Mímir-client voor Narcissus (zelfde configuratie als de andere sleutels-apps).
 *
 * auth.php (niet in git):
 *   $mimirApi  = 'mimir_…';                            // verplicht
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Narcissus praat alleen met Mímir (POST query.php) en nooit direct met Business Central.
 */

/**
 * Constants
 */

const NARCISSUS_MIMIR_DEFAULT_BASE = 'https://sleutels.kvt.nl/mimir/api';
const NARCISSUS_MIMIR_CONNECT_TIMEOUT = 15;
const NARCISSUS_MIMIR_TIMEOUT = 300;

/**
 * Classes
 */

class NarcissusMimirException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

/**
 * Functies
 */

function narcissus_mimir_api_key(): string
{
    global $mimirApi;

    return isset($mimirApi) && is_string($mimirApi) ? trim($mimirApi) : '';
}

function narcissus_mimir_enabled(): bool
{
    return narcissus_mimir_api_key() !== '';
}

function narcissus_mimir_base_url(): string
{
    global $mimirBase;

    if (isset($mimirBase) && is_string($mimirBase) && trim($mimirBase) !== '') {
        return rtrim(trim($mimirBase), '/');
    }

    return NARCISSUS_MIMIR_DEFAULT_BASE;
}

/**
 * Doet één HTTP-verzoek naar Mímir.
 *
 * @return array{status: int, body: string}
 */
function narcissus_mimir_http_post(string $url, string $apiKey, string $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, // geen redirects: de API-sleutel mag nooit naar een andere host
        CURLOPT_CONNECTTIMEOUT => NARCISSUS_MIMIR_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => NARCISSUS_MIMIR_TIMEOUT,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_USERAGENT => 'Narcissus-MimirClient/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'X-API-Key: ' . $apiKey,
        ],
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new NarcissusMimirException('Mímir niet bereikbaar: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $raw];
}

/**
 * Haalt alle rijen van één tabel op via Mímir (top 0 = volledige paginering).
 *
 * $filter is een Mímir-filterboom (and/or + bladeren) of een OData $filter-string.
 * $transport is alleen voor tests: fn(string $url, string $apiKey, string $payload): array{status, body}.
 *
 * @param list<string> $select
 * @param array<string, mixed>|string|null $filter
 * @return list<array<string, mixed>>
 */
function narcissus_mimir_query(
    string $company,
    string $table,
    array $select,
    $filter,
    int $maxAgeSeconds,
    ?callable $transport = null
): array {
    $apiKey = narcissus_mimir_api_key();
    if ($apiKey === '') {
        throw new NarcissusMimirException('Mímir is niet ingesteld ($mimirApi ontbreekt in auth.php).');
    }

    $body = [
        'company' => $company,
        'table' => $table,
        'max_age' => max(0, $maxAgeSeconds),
        'top' => 0,
    ];
    if ($select !== []) {
        $body['select'] = array_values($select);
    }
    if ($filter !== null && $filter !== '' && $filter !== []) {
        $body['filter'] = $filter;
    }

    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new NarcissusMimirException('Mímir-verzoek kon niet als JSON worden opgebouwd.');
    }

    $url = narcissus_mimir_base_url() . '/query.php';
    $response = $transport !== null
        ? $transport($url, $apiKey, $payload)
        : narcissus_mimir_http_post($url, $apiKey, $payload);

    $status = (int) ($response['status'] ?? 0);
    $raw = (string) ($response['body'] ?? '');
    $decoded = json_decode($raw, true);

    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : trim($raw);
        throw new NarcissusMimirException(
            'Mímir HTTP ' . $status . ($message !== '' ? ': ' . mb_substr($message, 0, 300) : ''),
            $status
        );
    }

    if (!is_array($decoded)) {
        throw new NarcissusMimirException('Mímir gaf geen geldige JSON terug.', $status);
    }

    $error = $decoded['error'] ?? null;
    if ($error !== null && $error !== '' && $error !== false) {
        $message = is_string($error) ? $error : (string) json_encode($error, JSON_UNESCAPED_UNICODE);
        throw new NarcissusMimirException('Mímir-fout: ' . mb_substr($message, 0, 300), $status);
    }

    $rows = $decoded['value'] ?? null;
    if (!is_array($rows)) {
        throw new NarcissusMimirException("Mímir-antwoord mist 'value'.", $status);
    }

    return array_values(array_filter($rows, 'is_array'));
}
