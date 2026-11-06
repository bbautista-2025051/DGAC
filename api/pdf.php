<?php

declare(strict_types=1);

/**
 * GET /api/pdf.php?token=...
 * Descarga el comprobante PDF. Se regenera a partir de los datos almacenados
 * para garantizar que coincida con el registro vigente.
 */

require __DIR__ . '/bootstrap.php';

use Dgac\Drones\ApiException;
use Dgac\Drones\RegistroService;
use function Dgac\Drones\enforce_rate_limit;
use function Dgac\Drones\fail;
use function Dgac\Drones\require_method;

require_method('GET');
enforce_rate_limit('pdf', 60, 3600);

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
if ($token === '') {
    fail(400, 'Falta el token del comprobante.', ['token' => 'El token es obligatorio.']);
}

try {
    $service = new RegistroService();
    $pdf = $service->pdf($token);

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
