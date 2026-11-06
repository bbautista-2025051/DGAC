<?php

declare(strict_types=1);

/**
 * GET /api/pdf.php?id=<registro> | ?token=<token>
 * Descarga el comprobante PDF. Solo usuarios internos autorizados (`admin`)
 * pueden descargar; el token por sí solo ya no funciona.
 */

require __DIR__ . '/bootstrap.php';

use Dgac\Drones\ApiException;
use Dgac\Drones\Auth;
use Dgac\Drones\RegistroService;
use function Dgac\Drones\enforce_rate_limit;
use function Dgac\Drones\fail;
use function Dgac\Drones\log_event;
use function Dgac\Drones\require_method;

require_method('GET');
enforce_rate_limit('pdf', 60, 3600);

$user = Auth::requireDownloadUser();

$idParam = $_GET['id'] ?? null;
$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

$id = null;
if ($idParam !== null && $idParam !== '') {
    if (!ctype_digit((string) $idParam)) {
        fail(400, 'El identificador del registro no es válido.');
    }

    $id = (int) $idParam;
    if ($id < 1) {
        fail(400, 'El identificador del registro no es válido.');
    }
}

if (($id === null) === ($token === '')) {
    fail(400, 'Indique exactamente un identificador id o un token.', [
        'id' => 'Use el identificador numérico o el token, no ambos.',
    ]);
}

try {
    $service = new RegistroService();
    $pdf = $id !== null ? $service->pdfById($id) : $service->pdf($token);

    log_event('info', 'Comprobante PDF descargado por usuario autorizado.', [
        'usuario_id' => (int) $user['id'],
        'registro_id' => $id ?? null,
        'ip' => \Dgac\Drones\client_ip(),
    ]);

    if (headers_sent()) {
        exit;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdf['filename'] . '"');
    header('Content-Length: ' . strlen($pdf['bytes']));
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    echo $pdf['bytes'];
    exit;
} catch (ApiException $e) {
    fail($e->status, $e->getMessage(), $e->errors);
}
