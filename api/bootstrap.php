<?php
/**
 * DGAC · Registro de Drones
 * Arranque común de la capa de integración (API).
 *
 * Este archivo NO crea una aplicación ni un servidor independiente: es el punto
 * de entrada de los endpoints que se despliegan junto al sitio existente. Puede
 * sustituirse por los controladores/rutas del backend actual conservando el
 * contrato descrito en el README.
 */

declare(strict_types=1);

namespace Dgac\Drones;

const DGAC_MODULE_VERSION = '1.0.0';

/* -------------------------------------------------------------------------- */
/* Entorno                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * Carga un archivo .env sencillo (CLAVE=valor) sin dependencias externas.
 * No sobreescribe variables ya definidas en el proceso.
 */
function load_env_file(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if (getenv($key) === false && !isset($_ENV[$key])) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

load_env_file(dirname(__DIR__) . '/.env');

function env_value(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);

    return ($value === false || $value === null || $value === '') ? $default : (string) $value;
}

function env_int(string $key, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
{
    $raw = env_value($key);
    if ($raw === null || !is_numeric($raw)) {
        return $default;
    }

    return max($min, min($max, (int) $raw));
}

$appEnv = env_value('APP_ENV', 'production');
date_default_timezone_set(env_value('APP_TIMEZONE', 'America/Guatemala') ?? 'America/Guatemala');

error_reporting(E_ALL);
ini_set('display_errors', $appEnv === 'local' ? '1' : '0');
ini_set('log_errors', '1');

/* -------------------------------------------------------------------------- */
/* Autoload de las clases del módulo                                           */
/* -------------------------------------------------------------------------- */

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/* -------------------------------------------------------------------------- */
/* Utilidades HTTP                                                             */
/* -------------------------------------------------------------------------- */

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
}

function security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
}

security_headers();

function json_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function fail(int $status, string $message, array $errors = [], array $extra = []): never
{
    json_response($status, array_merge([
        'ok' => false,
        'mensaje' => $message,
        'errores' => $errors,
    ], $extra));
}

function success(int $status, array $data, string $message = ''): never
{
    json_response($status, [
        'ok' => true,
        'mensaje' => $message,
        'data' => $data,
    ]);
}

function require_method(string $method): void
{
    $actual = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($actual !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        fail(405, 'Método no permitido.');
    }
}

/**
 * Lee y valida un cuerpo JSON. Limita el tamaño para evitar abusos.
 *
 * @return array<string, mixed>
 */
function read_json_body(int $maxBytes = 16384): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
        fail(415, 'El contenido debe enviarse como application/json.');
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        fail(400, 'El cuerpo de la solicitud está vacío.');
    }

    if (strlen($raw) > $maxBytes) {
        fail(413, 'La solicitud es demasiado grande.');
    }

    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        fail(400, 'El cuerpo de la solicitud no contiene JSON válido.');
    }

    if (!is_array($data)) {
        fail(400, 'El cuerpo de la solicitud debe ser un objeto JSON.');
    }

    return $data;
}

function client_ip(): string
{
    // No se confía en X-Forwarded-For por defecto: es falsificable. Si la app se
    // publica detrás de un proxy, configure el proxy para reescribir REMOTE_ADDR
    // o añada aquí una lista blanca de proxies de confianza.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/* -------------------------------------------------------------------------- */
/* CORS y origen                                                               */
/* -------------------------------------------------------------------------- */

function allowed_origins(): array
{
    $raw = env_value('ALLOWED_ORIGINS', '') ?? '';

    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' || headers_sent()) {
        return;
    }

    $allowed = allowed_origins();
    if ($allowed === []) {
        return; // mismo origen: no se emite CORS
    }

    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }
}

apply_cors();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Verifica que una petición de escritura provenga del mismo sitio (o de un
 * origen explícitamente permitido). Se complementa con el token CSRF.
 */
function assert_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return; // clientes no navegador; el CSRF sigue siendo obligatorio
    }

    if (allowed_origins() !== [] && in_array($origin, allowed_origins(), true)) {
        return;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $originHost = parse_url($origin, PHP_URL_HOST);
    $originPort = parse_url($origin, PHP_URL_PORT);
    $originAuthority = $originHost . ($originPort ? ':' . $originPort : '');

    $requestHost = parse_url('http://' . $host, PHP_URL_HOST);
    $requestPort = parse_url('http://' . $host, PHP_URL_PORT);
    $requestAuthority = $requestHost . ($requestPort ? ':' . $requestPort : '');

    if ($originAuthority === '' || $originAuthority !== $requestAuthority) {
        fail(403, 'Origen de la solicitud no autorizado.');
    }
}

/* -------------------------------------------------------------------------- */
/* Sesión y CSRF                                                               */
/* -------------------------------------------------------------------------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = is_https();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('DGACDRONESID');
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function assert_csrf(?string $token): void
{
    start_session();
    $expected = $_SESSION['csrf_token'] ?? '';

    if (!is_string($expected) || $expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
        fail(419, 'La sesión del formulario expiró. Recargue la página e intente nuevamente.');
    }
}

/* -------------------------------------------------------------------------- */
/* Almacenamiento y logs                                                       */
/* -------------------------------------------------------------------------- */

function storage_path(string $sub = ''): string
{
    $base = env_value('STORAGE_PATH');
    if ($base === null || trim($base) === '') {
        $base = dirname(__DIR__) . '/storage';
    }

    $base = rtrim($base, "/\\");
    $path = $sub === '' ? $base : $base . '/' . ltrim($sub, "/\\");

    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new \RuntimeException('No se pudo crear el directorio de almacenamiento: ' . $path);
    }

    return $path;
}

function log_event(string $level, string $message, array $context = []): void
{
    try {
        $dir = storage_path('logs');
        $line = json_encode([
            'ts' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (\Throwable) {
        // El logging nunca debe tumbar la petición.
    }
}

/**
 * Rate limit sencillo por IP y acción, basado en archivos y flock.
 */
function enforce_rate_limit(string $action, int $max, int $window): void
{
    if ($max <= 0 || $window <= 0) {
        return;
    }

    $dir = storage_path('rate');
    $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $action) . '-' . sha1(client_ip()) . '.json';
    $handle = fopen($file, 'c+');

    if ($handle === false) {
        throw new \RuntimeException('No se pudo abrir el archivo de rate limit.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        $contents = stream_get_contents($handle);
        $timestamps = [];
        if (is_string($contents) && $contents !== '') {
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $timestamps = array_values(array_filter($decoded, 'is_int'));
            }
        }

        $now = time();
        $timestamps = array_values(array_filter($timestamps, static fn (int $ts): bool => $ts > $now - $window));

        if (count($timestamps) >= $max) {
            $retryAfter = max(1, ($timestamps[0] + $window) - $now);
            header('Retry-After: ' . $retryAfter);
            fail(429, 'Ha realizado demasiadas solicitudes. Intente nuevamente en ' . ceil($retryAfter / 60) . ' minuto(s).');
        }

        $timestamps[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($timestamps));
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return '***';
    }

    $name = $parts[0];
    $visible = mb_substr($name, 0, 2);

    return $visible . str_repeat('*', max(1, mb_strlen($name) - 2)) . '@' . $parts[1];
}

function app_url(): string
{
    return rtrim(env_value('APP_URL', '') ?? '', '/');
}

/* -------------------------------------------------------------------------- */
/* Manejo global de errores                                                    */
/* -------------------------------------------------------------------------- */

set_exception_handler(static function (\Throwable $e): void {
    log_event('error', $e->getMessage(), [
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'ip' => client_ip(),
    ]);

    if (headers_sent()) {
        exit;
    }

    $local = env_value('APP_ENV', 'production') === 'local';
    json_response(500, [
        'ok' => false,
        'mensaje' => 'Ocurrió un error interno. Intente nuevamente en unos minutos.',
        'errores' => [],
        'detalle' => $local ? $e->getMessage() : null,
    ]);
});
