<?php
/**
 * Fantasma 👻 — api.php
 * ---------------------
 * Recibe por POST un JSON {"url":"https://..."} y crea un enlace corto REAL
 * en abre.ai desde el servidor (asi el navegador no tiene problemas de CORS).
 *
 * Responde siempre JSON:
 *   {"ok":true, "short":"https://abre.ai/xxxxxx"}
 *   {"ok":false,"error":"motivo"}
 *
 * Instalacion: sube este archivo junto a index.html a tu hosting. Nada mas.
 * Para comprobar que quedo bien instalado, visita en el navegador:
 *   https://tudominio.com/api.php
 * y debes ver: {"ok":true,"service":"fantasma-api",...}
 */

header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Visita directa en el navegador: comprobar que el archivo esta en su sitio.
if ($method === 'GET') {
    echo json_encode(
        ['ok' => true, 'service' => 'fantasma-api', 'msg' => 'API lista. Envia POST {"url":"https://..."}'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($method !== 'POST') {
    fail('Metodo no permitido', 405);
}

if (!function_exists('curl_init')) {
    fail('cURL no esta disponible en este hosting');
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    fail('Cuerpo JSON no valido');
}
$url = isset($data['url']) ? trim((string)$data['url']) : '';
if ($url === '') {
    fail('Falta la URL');
}
if (strlen($url) > 2048) {
    fail('URL demasiado larga');
}

// Si no trae esquema, se asume https://
if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $url)) {
    $url = 'https://' . $url;
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    fail('URL no valida');
}
$parts  = parse_url($url);
$scheme = strtolower($parts['scheme'] ?? '');
$host   = strtolower($parts['host'] ?? '');
if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
    fail('Solo se permiten URLs http/https con dominio valido');
}
if (isset($parts['user']) || isset($parts['pass'])) {
    fail('URL con credenciales no permitida');
}

// --- Proteccion: no permitir destinos internos/privados (SSRF) ---
function is_private_ipv4($h) {
    if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $h, $m)) {
        return false;
    }
    $o = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]];
    foreach ($o as $n) {
        if ($n < 0 || $n > 255) return false;
    }
    $a = $o[0];
    $b = $o[1];
    return $a === 10 || $a === 127 || $a === 0
        || ($a === 172 && $b >= 16 && $b <= 31)
        || ($a === 192 && $b === 168)
        || ($a === 169 && $b === 254);
}

function blocked_host($h) {
    $h = strtolower(trim($h, '[]'));
    if ($h === 'localhost' || $h === '::1' || $h === '0.0.0.0') return true;
    if (is_private_ipv4($h)) return true;
    if (strpos($h, 'fc') === 0 || strpos($h, 'fd') === 0) return true; // IPv6 privadas
    foreach (['.local', '.internal', '.localhost'] as $s) {
        if (substr($h, -strlen($s)) === $s) return true;
    }
    return false;
}

if (blocked_host($host)) {
    fail('Destino interno o privado no permitido');
}
// Resolver el dominio y comprobar que no apunta a una IP privada
$ip = gethostbyname($host);
if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    fail('No se pudo resolver el dominio');
}
if (is_private_ipv4($ip)) {
    fail('El dominio apunta a una direccion privada');
}

// --- Llamada real a abre.ai (igual que su extension oficial) ---
$payload = json_encode(['url_translation' => ['url' => $url, 'token' => '']]);
$ch = curl_init('https://abre.ai/_/generate.txt');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
]);
$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

if ($resp === false || $http < 200 || $http >= 300) {
    fail('abre.ai no respondio (' . ($cerr !== '' ? $cerr : 'HTTP ' . $http) . '). Intentalo de nuevo.');
}
$resp = trim((string)$resp);
if (!preg_match('#https?://[^\s"\'<>]+#', $resp, $m)) {
    fail('Respuesta irreconocible de abre.ai');
}
$short = $m[0];
$shost = strtolower((string)parse_url($short, PHP_URL_HOST));
if (substr($shost, -strlen('abre.ai')) !== 'abre.ai') {
    fail('Dominio inesperado en la respuesta');
}

echo json_encode(['ok' => true, 'short' => $short], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
