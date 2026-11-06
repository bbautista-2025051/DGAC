<?php

declare(strict_types=1);

/**
 * POST /api/reintentar.php
 * Reintenta el envío del comprobante sin volver a crear el registro.
 *
 * Cuerpo JSON: { "token": "...", "csrf_token": "..." }
 */

require __DIR__ . '/bootstrap.php';

use Dgac\Drones\ApiException;
use Dgac\Drones\RegistroService;
use function Dgac\Drones\assert_csrf;
use function Dgac\Drones\assert_same_origin;
use function Dgac\Drones\enforce_rate_limit;
use function Dgac\Drones\fail;
use function Dgac\Drones\read_json_body;
use function Dgac\Drones\require_method;
use function Dgac\Drones\success;

require_method('POST');
assert_same_origin();
enforce_rate_limit('reintentar', 20, 3600);

$body = read_json_body(4096);
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf_token'] ?? null);
assert_csrf(is_string($csrf) ? $csrf : null);

$token = isset($body['token']) ? trim((string) $body['token']) : '';
if ($token === '') {
    fail(400, 'Falta el token del comprobante.', ['token' => 'El token es obligatorio.']);
}

try {
    $service = new RegistroService();
    $registro = $service->retry($token);

    $message = $registro['correo_enviado']
        ? 'Comprobante reenviado correctamente a su correo electrónico.'
        : 'No fue posible enviar el correo. El registro se conserva y puede intentarlo nuevamente.';

    success(200, $registro, $message);
} catch (ApiException $e) {
    fail($e->status, $e->getMessage(), $e->errors);
}
