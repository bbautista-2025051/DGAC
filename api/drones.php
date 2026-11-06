<?php

declare(strict_types=1);

/**
 * GET /api/drones.php?q=&page=&per_page=
 * Lista los drones registrados. Requiere sesión interna (`admin` o `consulta`).
 * No incluye tokens; `admin` puede descargar el comprobante desde esta vista.
 */

require __DIR__ . '/bootstrap.php';

use Dgac\Drones\Auth;
use Dgac\Drones\RegistroService;
use function Dgac\Drones\env_int;
use function Dgac\Drones\enforce_rate_limit;
use function Dgac\Drones\json_response;
use function Dgac\Drones\log_event;
use function Dgac\Drones\require_method;

require_method('GET');
enforce_rate_limit('drones', env_int('DRONES_RATE_MAX', 240, 1, 1000), env_int('DRONES_RATE_WINDOW', 900, 60, 86400));

$user = Auth::requireUser();

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if (mb_strlen($q) > 120) {
    $q = mb_substr($q, 0, 120);
}

$page = isset($_GET['page']) && ctype_digit((string) $_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = isset($_GET['per_page']) && ctype_digit((string) $_GET['per_page'])
    ? max(1, min(25, (int) $_GET['per_page']))
    : 10;

$service = new RegistroService();
$result = $service->internalRecords($q, $page, $perPage);

log_event('info', 'Listado interno de registros consultado.', [
    'usuario_id' => (int) $user['id'],
    'rol' => (string) $user['rol'],
    'busqueda_filtro' => $q !== '',
    'pagina' => $page,
    'total' => (int) ($result['pagination']['total'] ?? 0),
]);

json_response(200, [
    'ok' => true,
    'mensaje' => '',
    'data' => [
        'authenticated' => true,
        'user' => $user,
        'items' => $result['items'],
        'pagination' => $result['pagination'],
        'puede_descargar' => (bool) $user['puede_descargar'],
    ],
]);
