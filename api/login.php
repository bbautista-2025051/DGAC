<?php

declare(strict_types=1);

/**
 * POST /api/login.php
 * Inicia sesión de un usuario interno autorizado. El acceso solo está permitido
 * para correos creados en la tabla usuarios_dgac; no existe registro público.
 */

require __DIR__ . '/bootstrap.php';

use Dgac\Drones\Auth;
use function Dgac\Drones\assert_csrf;
use function Dgac\Drones\assert_same_origin;
use function Dgac\Drones\json_response;
use function Dgac\Drones\read_json_body;
use function Dgac\Drones\require_method;

require_method('POST');
assert_same_origin();

$body = read_json_body(8192);
assert_csrf(isset($body['csrf_token']) ? (string) $body['csrf_token'] : null);

$user = Auth::login(
    (string) ($body['correo'] ?? ''),
    (string) ($body['password'] ?? '')
);

json_response(200, [
    'ok' => true,
    'mensaje' => 'Sesión iniciada correctamente.',
    'data' => [
        'authenticated' => true,
        'user' => $user,
    ],
]);
