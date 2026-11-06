<?php

declare(strict_types=1);

/**
 * GET /api/csrf.php
 * Entrega el token CSRF de la sesión y crea la cookie HttpOnly.
 */

require __DIR__ . '/bootstrap.php';

use function Dgac\Drones\csrf_token;
use function Dgac\Drones\json_response;
use function Dgac\Drones\log_event;
use function Dgac\Drones\require_method;

require_method('GET');

try {
    $token = csrf_token();
    json_response(200, [
        'ok' => true,
        'csrf_token' => $token,
    ]);
} catch (Throwable $e) {
    log_event('error', 'No se pudo iniciar la sesión CSRF.', ['error' => $e->getMessage()]);
    json_response(500, [
        'ok' => false,
        'mensaje' => 'No se pudo iniciar la sesión del formulario. Recargue la página.',
        'errores' => [],
    ]);
}
