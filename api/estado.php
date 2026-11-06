<?php

declare(strict_types=1);

/**
 * GET /api/estado.php?token=...
 * Consulta el estado de un registro usando el token del comprobante.
 * El token es una capacidad aleatoria de 64 caracteres; no se expone el ID solo.
 */

require __DIR__ . '/bootstrap.php';

use Dgac\Drones\ApiException;
use Dgac\Drones\RegistroService;
use function Dgac\Drones\enforce_rate_limit;
use function Dgac\Drones\fail;
use function Dgac\Drones\require_method;
use function Dgac\Drones\success;

require_method('GET');
enforce_rate_limit('estado', 120, 3600);

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
if ($token === '') {
    fail(400, 'Falta el token del comprobante.', ['token' => 'El token es obligatorio.']);
}

try {
    $service = new RegistroService();
    success(200, $service->status($token));
} catch (ApiException $e) {
    fail($e->status, $e->getMessage(), $e->errors);
}
