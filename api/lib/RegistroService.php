<?php

declare(strict_types=1);

namespace Dgac\Drones;

use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

/**
 * Lógica de negocio del registro de drones.
 *
 * Reutiliza la conexión PDO de Database. Si la aplicación existente ya tiene un
 * repositorio/servicio equivalente, mueva estas consultas allí y mantenga el
 * contrato JSON de api/*.php.
 */
final class RegistroService
{
    private const TABLE = 'registro_drones';

    private PDO $pdo;
    private int $maxRetries;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->maxRetries = env_int('MAIL_MAX_RETRIES', 5, 1, 20);
    }

    /**
     * Crea el registro, genera el PDF, intenta enviarlo y persiste el estado.
     * Si el correo falla, el registro se conserva y queda disponible para reintento.
     *
     * @param array<string, string> $datos Datos ya validados y normalizados.
     * @return array<string, mixed>
     */
    public function create(array $datos): array
    {
        // Verificación temprana de duplicados (la restricción UNIQUE sigue siendo
        // la garantía definitiva ante dos peticiones concurrentes).
        $check = $this->pdo->prepare('SELECT id FROM ' . self::TABLE . ' WHERE numero_serie = :serie LIMIT 1');
        $check->execute(['serie' => $datos['numero_serie']]);
        if ($check->fetchColumn() !== false) {
            throw new ApiException(409, 'El número de serie ya está registrado.', [
                'numero_serie' => 'Este número de serie ya cuenta con un registro en el sistema.',
            ]);
        }

        $token = bin2hex(random_bytes(32));

        $sql = 'INSERT INTO ' . self::TABLE . ' (
                    marca_dron, modelo, numero_serie, nombre_propietario,
                    correo_contacto, fecha_registro, estado_registro, pdf_token,
                    intentos_envio
                ) VALUES (
                    :marca_dron, :modelo, :numero_serie, :nombre_propietario,
                    :correo_contacto, :fecha_registro, :estado_registro, :pdf_token,
                    0
                )';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'marca_dron' => $datos['marca_dron'],
                'modelo' => $datos['modelo'],
                'numero_serie' => $datos['numero_serie'],
                'nombre_propietario' => $datos['nombre_propietario'],
                'correo_contacto' => $datos['correo_contacto'],
                'fecha_registro' => date('Y-m-d H:i:s'),
                'estado_registro' => 'REGISTRADO',
                'pdf_token' => $token,
            ]);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                throw new ApiException(409, 'El número de serie ya está registrado.', [
                    'numero_serie' => 'Este número de serie ya cuenta con un registro en el sistema.',
                ]);
            }
            throw $e;
        }

        $id = (int) $this->pdo->lastInsertId();
        $registro = $this->findById($id);

        log_event('info', 'Registro de dron almacenado.', [
            'id' => $id,
            'codigo' => $this->codigo($registro),
            'ip' => client_ip(),
        ]);

        try {
            $this->deliver($registro);
        } catch (Throwable $e) {
            $this->markDeliveryFailure($id, $e);
        }

        return $this->toPublicArray($this->findById($id));
    }

    /**
     * Devuelve un PDF para descarga sin revelar si el solicitante es público o
     * interno. `pdf.php` debe autorizar la sesión antes de llamarlo.
     *
     * @return array{bytes:string, filename:string, codigo:string}
     */
    public function pdfById(int $id): array
    {
        $registro = $this->findById($id);
        $codigo = $this->codigo($registro);
        $bytes = $this->generatePdf($registro);

        return [
            'bytes' => $bytes,
            'filename' => 'Comprobante-' . $codigo . '.pdf',
            'codigo' => $codigo,
        ];
    }

    /**
     * Lista registros para personal interno autorizado.
     *
     * @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>}
     */
    public function internalRecords(string $query, int $page, int $perPage): array
    {
        $query = trim($query);
        $page = max(1, $page);
        $perPage = max(1, min(25, $perPage));

        $where = '';
        $params = [];
        if ($query !== '') {
            $where = ' WHERE (
                LOCATE(LOWER(:q), LOWER(marca_dron)) > 0
                OR LOCATE(LOWER(:q), LOWER(modelo)) > 0
                OR LOCATE(LOWER(:q), LOWER(numero_serie)) > 0
                OR LOCATE(LOWER(:q), LOWER(nombre_propietario)) > 0
                OR LOCATE(LOWER(:q), LOWER(correo_contacto)) > 0
            )';
            $params['q'] = $query;
        }

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT id, marca_dron, modelo, numero_serie, nombre_propietario,
                       correo_contacto, fecha_registro, estado_registro, intentos_envio
                FROM ' . self::TABLE . $where . '
                ORDER BY fecha_registro DESC, id DESC
                LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(fn (array $row): array => $this->toInternalRecord($row), $rows);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Consulta pública limitada por número de serie y correo del titular.
     * No revela datos personales ni capacidades de descarga.
     *
     * @return array<string,mixed>|null
     */
    public function publicSearch(string $numeroSerie, string $correo): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, marca_dron, modelo, numero_serie, fecha_registro, estado_registro
             FROM ' . self::TABLE . '
             WHERE numero_serie = :serie AND LOWER(correo_contacto) = LOWER(:correo)
             LIMIT 1'
        );
        $stmt->execute([
            'serie' => $numeroSerie,
            'correo' => mb_strtolower($correo, 'UTF-8'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->toPublicSearchResult($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function codigoForRow(array $row): string
    {
        return $this->codigo($row);
    }

    /**
     * Busca el registro público asociado a un token y, si el solicitante tiene
     * permiso de descarga, incluye la información operativa necesaria.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toInternalRecord(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'codigo' => $this->codigo($row),
            'marca_dron' => (string) $row['marca_dron'],
            'modelo' => (string) $row['modelo'],
            'numero_serie' => (string) $row['numero_serie'],
            'nombre_propietario' => (string) $row['nombre_propietario'],
            'correo_contacto' => (string) $row['correo_contacto'],
            'fecha_registro' => (string) $row['fecha_registro'],
            'estado_registro' => (string) $row['estado_registro'],
            'intentos_envio' => (int) ($row['intentos_envio'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toPublicSearchResult(array $row): array
    {
        return [
            'codigo' => $this->codigo($row),
            'marca_dron' => (string) $row['marca_dron'],
            'modelo' => (string) $row['modelo'],
            'numero_serie' => (string) $row['numero_serie'],
            'fecha_registro' => (string) $row['fecha_registro'],
            'estado_registro' => (string) $row['estado_registro'],
        ];
    }

    /**
     * Reintenta el envío del comprobante. No vuelve a crear el registro.
     *
     * @return array<string, mixed>
     */
    public function retry(string $token): array
    {
        $registro = $this->findByToken($token);

        if (($registro['estado_registro'] ?? '') === 'CORREO_ENVIADO') {
            return $this->toPublicArray($registro);
        }

        $attempts = (int) ($registro['intentos_envio'] ?? 0);
        if ($attempts >= $this->maxRetries) {
            throw new ApiException(
                429,
                'Se alcanzó el máximo de reintentos automáticos. Contacte a la DGAC para completar el envío.',
                [],
                // Los detalles adicionales se agregan en el endpoint.
            );
        }

        try {
            $this->deliver($registro);
        } catch (Throwable $e) {
            $this->markDeliveryFailure((int) $registro['id'], $e);
        }

        return $this->toPublicArray($this->findById((int) $registro['id']));
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $token): array
    {
        return $this->toPublicArray($this->findByToken($token));
    }

    /**
     * Genera (o regenera) el PDF y devuelve sus bytes para descarga.
     *
     * @return array{bytes:string, filename:string, codigo:string}
     */
    public function pdf(string $token): array
    {
        $registro = $this->findByToken($token);
        $codigo = $this->codigo($registro);
        $bytes = $this->generatePdf($registro);

        return [
            'bytes' => $bytes,
            'filename' => 'Comprobante-DGAC-' . $codigo . '.pdf',
            'codigo' => $codigo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new ApiException(404, 'El registro no existe.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function findByToken(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new ApiException(404, 'El comprobante solicitado no existe.');
        }

        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE pdf_token = :token LIMIT 1');
        $stmt->execute(['token' => strtolower($token)]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new ApiException(404, 'El comprobante solicitado no existe.');
        }

        return $row;
    }

    /**
     * Genera el PDF, lo guarda y lo envía por correo. Lanza excepción si falla
     * cualquiera de los pasos; el llamador conserva el registro y marca el error.
     *
     * @param array<string, mixed> $registro
     */
    private function deliver(array $registro): void
    {
        $codigo = $this->codigo($registro);
        $registro['codigo'] = $codigo;

        $bytes = $this->generatePdf($registro);
        $filename = 'Comprobante-DGAC-' . $codigo . '.pdf';

        $mailer = new Mailer();
        $mailer->sendRegistro($registro, $bytes, $filename);

        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET estado_registro = :estado,
                 correo_enviado_en = :enviado,
                 intentos_envio = intentos_envio + 1,
                 ultimo_error = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            'estado' => 'CORREO_ENVIADO',
            'enviado' => date('Y-m-d H:i:s'),
            'id' => $registro['id'],
        ]);

        log_event('info', 'Comprobante enviado por correo.', [
            'id' => (int) $registro['id'],
            'codigo' => $codigo,
        ]);
    }

    /**
     * @param array<string, mixed> $registro
     */
    private function generatePdf(array $registro): string
    {
        $registro['codigo'] = $this->codigo($registro);
        $pdf = (new PdfDocument())->buildRegistro($registro, app_url());

        if (!str_starts_with($pdf, '%PDF-')) {
            throw new \RuntimeException('El generador de PDF produjo un archivo inválido.');
        }

        $this->savePdf($registro, $pdf);

        return $pdf;
    }

    /**
     * @param array<string, mixed> $registro
     */
    private function savePdf(array $registro, string $bytes): void
    {
        $directory = storage_path('pdf');
        $path = $directory . '/registro-' . (int) $registro['id'] . '-' . (string) $registro['pdf_token'] . '.pdf';
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir el comprobante PDF.');
        }

        @chmod($tmp, 0640);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo guardar el comprobante PDF.');
        }
    }

    private function markDeliveryFailure(int $id, Throwable $error): void
    {
        $message = mb_substr($error->getMessage(), 0, 500);

        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET estado_registro = :estado,
                 intentos_envio = intentos_envio + 1,
                 ultimo_error = :error
             WHERE id = :id'
        );
        $stmt->execute([
            'estado' => 'CORREO_FALLIDO',
            'error' => $message,
            'id' => $id,
        ]);

        log_event('warning', 'No se pudo enviar el comprobante; el registro se conserva.', [
            'id' => $id,
            'error' => $message,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function codigo(array $row): string
    {
        $fecha = (string) ($row['fecha_registro'] ?? 'now');
        try {
            $year = (new DateTimeImmutable($fecha))->format('Y');
        } catch (Throwable) {
            $year = date('Y');
        }

        return 'DGAC-RPA-' . $year . '-' . str_pad((string) (int) $row['id'], 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toPublicArray(array $row): array
    {
        $estado = (string) $row['estado_registro'];
        $intentos = (int) $row['intentos_envio'];
        $token = (string) $row['pdf_token'];
        $mayDescargar = Auth::canCurrentUserDownload();

        $public = [
            'id' => (int) $row['id'],
            'codigo' => $this->codigo($row),
            'marca_dron' => (string) $row['marca_dron'],
            'modelo' => (string) $row['modelo'],
            'numero_serie' => (string) $row['numero_serie'],
            'nombre_propietario' => (string) $row['nombre_propietario'],
            'correo_contacto' => (string) $row['correo_contacto'],
            'fecha_registro' => (string) $row['fecha_registro'],
            'estado_registro' => $estado,
            'correo_enviado' => $estado === 'CORREO_ENVIADO',
            'intentos_envio' => $intentos,
            'reintento_disponible' => $estado !== 'CORREO_ENVIADO' && $intentos < $this->maxRetries,
            'puede_descargar' => $mayDescargar,
            'token_comprobante' => $token,
        ];

        if ($mayDescargar) {
            $public['pdf_url'] = 'api/pdf.php?id=' . rawurlencode((string) $row['id']);
        }

        return $public;
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        return $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
