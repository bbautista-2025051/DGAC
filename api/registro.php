<?php

declare(strict_types=1);

/**
 * POST /api/registro.php
 * Crea el registro, genera el PDF e intenta enviarlo por correo.
 *
 * Cuerpo JSON:
 * {
 *   "marca_dron": "...",
 *   "modelo": "...",
 *   "numero_serie": "...",
 *   "nombre_propietario": "...",
 *   "correo_contacto": "...",
 *   "csrf_token": "..."
 * }
 */

require __DIR__ . '/bootstrap.php';

use Dgac\Drones\ApiException;
use Dgac\Drones\RegistroService;
use Dgac\Drones\Validator;
use function Dgac\Drones\assert_csrf;
use function Dgac\Drones\assert_same_origin;
use function Dgac\Drones\client_ip;
use function Dgac\Drones\enforce_rate_limit;
use function Dgac\Drones\env_int;
use function Dgac\Drones\fail;
use function Dgac\Drones\read_json_body;
use function Dgac\Drones\require_method;
use function Dgac\Drones\success;

require_method('POST');
assert_same_origin();
enforce_rate_limit('registro', env_int('RATE_LIMIT_MAX', 10, 1, 1000), env_int('RATE_LIMIT_WINDOW', 3600, 60, 86400));

$body = read_json_body();
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf_token'] ?? null);
assert_csrf(is_string($csrf) ? $csrf : null);

$validated = Validator::validateRegistro($body);
if ($validated['errores'] !== []) {
    fail(422, 'Revise los campos marcados en el formulario.', $validated['errores']);
}

try {
    $service = new RegistroService();
    $registro = $service->create($validated['datos']);

    $message = $registro['correo_enviado']
        ? 'Registro completado. El comprobante fue enviado a su correo electrónico.'
        : 'El registro se guardó correctamente, pero no se pudo enviar el correo. Puede reintentar el envío.';

    success(201, $registro, $message);
} catch (ApiException $e) {
    fail($e->status, $e->getMessage(), $e->errors, [
        'ip' => client_ip(),
    ]);
}
