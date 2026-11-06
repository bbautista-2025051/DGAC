<?php

declare(strict_types=1);

namespace Dgac\Drones;

use RuntimeException;

/**
 * Error controlado de la capa de integración. El endpoint lo traduce a una
 * respuesta JSON con el código HTTP y los errores por campo indicados.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }
}
