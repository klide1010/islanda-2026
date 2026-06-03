<?php
/**
 * isl-proxy.php — Proxy per API islandesi (meteo + strade)
 *
 * Uso:   ?type=meteo&key=CHIAVE
 *         ?type=strade&key=CHIAVE
 *         ?type=debug&key=CHIAVE
 */

define('API_KEY', 'islanda2026-pippo-pluto');
define('TIMEOUT', 15);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$key = $_GET['key'] ?? '';
$type = $_GET['type'] ?? '';

if ($key !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Chiave non valida']);
    exit;
}

$endpoints = [
    // Feed Atom CAP ufficiale Veðurstofa Íslands — allerte meteo attive
    'meteo' => 'https://api.vedur.is/cap/v1/capbroker/active/feed/met',
    // API JSON Vegagerðin — condizioni stradali in tempo reale
    // Documentazione: http://gagnaveita.vegagerdin.is/api/faerd2017_1
    'strade' => 'http://gagnaveita.vegagerdin.is/api/faerd2017_1',
];

// ─── FETCH con cURL ────────────────────────────────────────────────
function do_fetch(string $url, int $timeout = TIMEOUT): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json, application/xml, */*'],
        CURLOPT_USERAGENT => 'IslandaPWA/2.0',
        CURLOPT_ENCODING => 'gzip, deflate',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$body, $code, $err];
}

// ─── DEBUG ─────────────────────────────────────────────────────────
if ($type === 'debug') {
    $out = [
        'php_version' => PHP_VERSION,
        'curl_available' => function_exists('curl_init'),
        'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
        'openssl' => extension_loaded('openssl'),
        'simplexml' => extension_loaded('simplexml'),
        'endpoints' => [],
    ];
    foreach ($endpoints as $name => $url) {
        [$body, $code, $err] = do_fetch($url, 8);
        $preview = $body ? substr($body, 0, 300) : null;
        $out['endpoints'][$name] = [
            'url' => $url,
            'http_code' => $code,
            'curl_error' => $err ?: null,
            'preview' => $preview,
        ];
    }
    echo json_encode(['ok' => true, 'debug' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($endpoints[$type])) {
    http_response_code(400);
    echo json_encode(['error' => 'type non valido. Usa: meteo | strade | debug']);
    exit;
}

// ─── FETCH DATI ────────────────────────────────────────────────────
$url = $endpoints[$type];
[$body, $http_code, $curl_err] = do_fetch($url);

if ($body === false || $body === '' || $http_code >= 400) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Errore nel recupero dati dalla sorgente',
        'source' => $url,
        'http_code' => $http_code,
        'curl_error' => $curl_err ?: null,
        'tip' => 'Apri ?type=debug&key=' . API_KEY . ' per diagnosticare',
    ]);
    exit;
}

// ─── METEO: parse feed CAP (Atom/XML) → JSON ──────────────────────
if ($type === 'meteo') {

    if (!extension_loaded('simplexml')) {
        http_response_code(500);
        echo json_encode(['error' => 'SimpleXML non disponibile sul server']);
        exit;
    }

    libxml_use_internal_errors(true);

    // Il feed CAP può avere un BOM UTF-8 — rimuoviamolo
    $body = ltrim($body, "\xEF\xBB\xBF");

    $xml = simplexml_load_string($body);

    if ($xml === false) {
        $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
        http_response_code(502);
        echo json_encode([
            'error' => 'Feed XML non valido dalla sorgente meteo',
            'errors' => $errs,
            'raw' => substr($body, 0, 400),
        ]);
        exit;
    }

    // Registra tutti i namespace presenti nel documento
    $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
    $xml->registerXPathNamespace('cap', 'urn:oasis:names:tc:emergency:cap:1.2');

    $items = [];

    // Prova Atom feed (/feed/entry)
    $entries = $xml->xpath('//atom:entry');

    // Fallback RSS (/rss/channel/item)
    if (empty($entries)) {
        $entries = $xml->xpath('//item') ?: [];
    }

    // Fallback: root è già un <alert> CAP singolo
    if (empty($entries) && $xml->getName() === 'alert') {
        $entries = [$xml];
    }

    foreach ($entries as $entry) {
        $ns = $entry->getNamespaces(true);

        // ── Titolo ──────────────────────────────────────────────
        $title = '';
        if (isset($entry->title)) {
            $title = (string) $entry->title;
        } elseif ($t = $entry->xpath('atom:title')) {
            $title = (string) $t[0];
        }

        // ── Summary / Descrizione ────────────────────────────────
        $desc = '';
        foreach (['summary', 'description', 'content'] as $f) {
            if (isset($entry->$f)) {
                $desc = (string) $entry->$f;
                break;
            }
        }
        if (!$desc) {
            foreach ($entry->xpath('atom:summary|atom:content|atom:description') as $n) {
                $desc = (string) $n;
                break;
            }
        }
        // Strip HTML
        $desc = strip_tags(html_entity_decode($desc, ENT_QUOTES, 'UTF-8'));

        // ── Link ─────────────────────────────────────────────────
        $link = '';
        if (isset($entry->link)) {
            $link = (string) ($entry->link['href'] ?? $entry->link);
        }

        // ── Campi CAP (severity, event, areaDesc) ─────────────
        $severity = $event = $areaDesc = '';

        // Cerca nei namespace CAP
        foreach ($ns as $prefix => $nsUri) {
            if (str_contains($nsUri, 'emergency:cap') || $prefix === 'cap') {
                $cap = $entry->children($nsUri);
                if (isset($cap->severity))
                    $severity = (string) $cap->severity;
                if (isset($cap->event))
                    $event = (string) $cap->event;
                // areaDesc può stare dentro <info><area>
                if (isset($cap->info)) {
                    foreach ($cap->info as $info) {
                        if (isset($info->area->areaDesc)) {
                            $areaDesc = (string) $info->area->areaDesc;
                            break;
                        }
                        if (!$event && isset($info->event))
                            $event = (string) $info->event;
                        if (!$severity && isset($info->severity))
                            $severity = (string) $info->severity;
                    }
                }
                break;
            }
        }

        // Fallback: prova XPath con prefisso cap
        if (!$severity) {
            foreach ($entry->xpath('.//cap:severity') as $n) {
                $severity = (string) $n;
                break;
            }
        }
        if (!$event) {
            foreach ($entry->xpath('.//cap:event') as $n) {
                $event = (string) $n;
                break;
            }
        }
        if (!$areaDesc) {
            foreach ($entry->xpath('.//cap:areaDesc') as $n) {
                $areaDesc = (string) $n;
                break;
            }
        }

        // Ultimo fallback: cerca la keyword nel testo
        if (!$severity && preg_match('/(Extreme|Severe|Moderate|Minor)/i', $title . $desc, $m)) {
            $severity = ucfirst(strtolower($m[1]));
        }

        // Salta allerte vuote (es. link di sistema senza contenuto)
        if (!$title && !$event && !$desc)
            continue;

        $items[] = [
            'title' => $title ?: $event ?: 'Allerta meteo',
            'desc' => $desc ? substr($desc, 0, 300) : null,
            'link' => $link,
            'severity' => $severity ?: 'Unknown',
            'event' => $event,
            'areaDesc' => $areaDesc,
        ];
    }

    echo json_encode([
        'ok' => true,
        'type' => 'meteo',
        'source' => $url,
        'timestamp' => date('c'),
        'count' => count($items),
        'data' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── STRADE: API JSON Vegagerðin ──────────────────────────────────
// La risposta è un array JSON di oggetti con campi:
// IdButur, StuttNafnButs, Astand (condizione), DagsSkrad (timestamp), ecc.

$raw = json_decode($body, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($raw)) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Risposta non JSON dalla sorgente strade',
        'details' => json_last_error_msg(),
        'raw' => substr($body, 0, 400),
    ]);
    exit;
}

// Strade dell'itinerario: 1 (Ring Road), 26, 35, 36, 37, 39, 249, 254, 427
// Normalizziamo i dati per renderli compatibili con la PWA
$STRADE_ITINERARIO = ['1', '26', '35', '36', '37', '39', '249', '254', '427'];

$normalized = [];
foreach ($raw as $r) {
    if (!is_array($r))
        continue;

    // Campi variabili a seconda delle versioni API
    $roadNum = (string) ($r['VegNumer'] ?? $r['Road'] ?? $r['NrVegar'] ?? '');
    $roadName = (string) ($r['StuttNafnButs'] ?? $r['Name'] ?? $r['Nafn'] ?? '');
    $astand = (string) ($r['Astand'] ?? $r['Condition'] ?? $r['Status'] ?? '');
    $ts = (string) ($r['DagsSkrad'] ?? $r['Timestamp'] ?? '');

    // Includi solo le strade del nostro itinerario O quelle con problemi
    $isItinerary = in_array($roadNum, $STRADE_ITINERARIO, true);
    $astandLow = strtolower($astand);
    $hasIssue = str_contains($astandLow, 'clos') ||
        str_contains($astandLow, 'diff') ||
        str_contains($astandLow, 'impass') ||
        str_contains($astandLow, 'loka') ||   // chiusa in islandese
        str_contains($astandLow, 'þung') ||   // pesante/difficile
        str_contains($astandLow, 'illf');     // difficoltà

    if (!$isItinerary && !$hasIssue)
        continue;

    $normalized[] = [
        'Road' => $roadNum,
        'Name' => $roadName,
        'Status' => $astand,
        'Timestamp' => $ts,
    ];
}

echo json_encode([
    'ok' => true,
    'type' => 'strade',
    'source' => $url,
    'timestamp' => date('c'),
    'count' => count($normalized),
    'data' => $normalized,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
