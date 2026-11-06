<?php

declare(strict_types=1);

/**
 * POST /api/buscar.php
 * Búsqueda pública limitada por número de serie + correo del propietario.
 * No expone nombre, correo, ID ni token; solo los datos básicos necesarios
 * para que el titular identifique su registro. No permite descarga.
 */

require __DIR__ . '/bootstrap.php';

use Dgac\Drones\RegistroService;
use Dgac\Drones\Validator;
use function Dgac\Drones\assert_csrf;
use function Dgac\Drones\assert_same_origin;
use function Dgac\Drones\env_int;
use function Dgac\Drones\enforce_rate_limit;
use function Dgac\Drones\fail;
use function Dgac\Drones\json_response;
use function Dgac\Drones\read_json_body;
use function Dgac\Drones\require_method;

require_method('POST');
assert_same_origin();
enforce_rate_limit('buscar', env_int('SEARCH_RATE_MAX', 20, 1, 100), env_int('SEARCH_RATE_WINDOW', 900, 60, 86400));

$body = read_json_body(4096);
assert_csrf(isset($body['csrf_token']) ? (string) $body['csrf_token'] : null);

$serie = Validator::normalizeSerie($body['numero_serie'] ?? '');
$correo = Validator::normalizeCorreo($body['correo_contacto'] ?? '');

$errores = [];

if ($serie === '' || !Validator::isValidSerie($serie)) {
    $errores['numero_serie'] = 'Ingrese un número de serie válido (6 a 40 caracteres).';
}

if ($correo === '' || !Validator::isValidCorreo($correo)) {
    $errores['correo_contacto'] = 'Ingrese un correo electrónico válido.';
}

if ($errores !== []) {
    fail(422, 'Revise los datos de la búsqueda.', $errores);
}

$service = new RegistroService();
$resultado = $service->publicSearch($serie, $correo);

if ($resultado === null) {
    // Respuesta genérica para no revelar si existe el número de serie o el correo.
    fail(404, 'No se encontró un registro con esos datos.');
}

json_response(200, [
    'ok' => true,
    'mensaje' => 'Registro encontrado.',
    'data' => $resultado,
]);
