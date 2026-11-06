<?php

declare(strict_types=1);

/**
 * GET /api/sesion.php
 * Informa si hay un usuario interno autenticado. No revela usuarios ni roles
 * inexistentes; responde a clientes legítimos de la página.
 */

require __DIR__ . '/bootstrap.php';

use Dgac\Drones\Auth;
use function Dgac\Drones\json_response;
use function Dgac\Drones\require_method;
use function Dgac\Drones\start_session;

require_method('GET');
start_session();

$user = Auth::currentUser();

json_response(200, [
    'ok' => true,
    'mensaje' => '',
    'data' => [
        'authenticated' => $user !== null,
        'user' => $user,
    ],
]);
