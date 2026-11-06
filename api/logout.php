<?php

declare(strict_types=1);

/**
 * POST /api/logout.php
 * Cierra la sesión del usuario interno. Requiere CSRF y mismo origen.
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

$body = read_json_body(2048);
assert_csrf(isset($body['csrf_token']) ? (string) $body['csrf_token'] : null);

Auth::logout();

json_response(200, [
    'ok' => true,
    'mensaje' => 'Sesión cerrada correctamente.',
    'data' => [
        'authenticated' => false,
        'user' => null,
    ],
]);
