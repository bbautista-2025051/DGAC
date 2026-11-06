<?php

declare(strict_types=1);

namespace Dgac\Drones;

/**
 * Validación y normalización del formulario.
 *
 * El frontend valida para dar respuesta inmediata; esta clase es la validación
 * definitiva y no debe omitirse aunque el navegador ya haya validado.
 */
final class Validator
{
    /**
     * Normaliza un número de serie con las mismas reglas del registro.
     */
    public static function normalizeSerie(mixed $value): string
    {
        $serie = preg_replace('/\s+/u', '', self::text($value)) ?? '';

        return mb_strtoupper($serie, 'UTF-8');
    }

    /**
     * Normaliza un correo electrónico con las mismas reglas del registro.
     */
    public static function normalizeCorreo(mixed $value): string
    {
        return mb_strtolower(self::text($value), 'UTF-8');
    }

    public static function isValidSerie(string $serie): bool
    {
        return preg_match('/^[A-Z0-9][A-Z0-9._-]{5,39}$/', $serie) === 1;
    }

    public static function isValidCorreo(string $correo): bool
    {
        return $correo !== '' &&
            mb_strlen($correo) <= 190 &&
            filter_var($correo, FILTER_VALIDATE_EMAIL) !== false &&
            preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/u', $correo) === 1;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{datos: array<string, string>, errores: array<string, string>}
     */
    public static function validateRegistro(array $input): array
    {
        $datos = [
            'marca_dron' => self::text($input['marca_dron'] ?? null),
            'modelo' => self::text($input['modelo'] ?? null),
            'numero_serie' => self::text($input['numero_serie'] ?? null),
            'nombre_propietario' => self::text($input['nombre_propietario'] ?? null),
            'correo_contacto' => self::text($input['correo_contacto'] ?? null),
        ];

        $errores = [];

        // ---------------------------------------------------------------------
        // Marca
        // ---------------------------------------------------------------------
        if ($datos['marca_dron'] === '') {
            $errores['marca_dron'] = 'La marca del dron es obligatoria.';
        } elseif (mb_strlen($datos['marca_dron']) < 2 || mb_strlen($datos['marca_dron']) > 80) {
            $errores['marca_dron'] = 'La marca debe tener entre 2 y 80 caracteres.';
        } elseif (!preg_match("/^[\\p{L}\\p{N} .,&+\\-_\\/()'°]+$/u", $datos['marca_dron'])) {
            $errores['marca_dron'] = 'La marca contiene caracteres no permitidos.';
        }

        // ---------------------------------------------------------------------
        // Modelo
        // ---------------------------------------------------------------------
        if ($datos['modelo'] === '') {
            $errores['modelo'] = 'El modelo del dron es obligatorio.';
        } elseif (mb_strlen($datos['modelo']) < 2 || mb_strlen($datos['modelo']) > 80) {
            $errores['modelo'] = 'El modelo debe tener entre 2 y 80 caracteres.';
        } elseif (!preg_match("/^[\\p{L}\\p{N} .,&+\\-_\\/()'°]+$/u", $datos['modelo'])) {
            $errores['modelo'] = 'El modelo contiene caracteres no permitidos.';
        }

        // ---------------------------------------------------------------------
        // Número de serie
        // ---------------------------------------------------------------------
        $serie = preg_replace('/\s+/u', '', $datos['numero_serie']) ?? '';
        $serie = mb_strtoupper($serie, 'UTF-8');
        $datos['numero_serie'] = $serie;

        if ($serie === '') {
            $errores['numero_serie'] = 'El número de serie es obligatorio.';
        } elseif (mb_strlen($serie) < 6 || mb_strlen($serie) > 40) {
            $errores['numero_serie'] = 'El número de serie debe tener entre 6 y 40 caracteres.';
        } elseif (!preg_match('/^[A-Z0-9][A-Z0-9._-]{5,39}$/', $serie)) {
            $errores['numero_serie'] = 'Use solo letras, números, puntos, guiones o guiones bajos. No se permiten espacios.';
        }

        // ---------------------------------------------------------------------
        // Nombre del propietario
        // ---------------------------------------------------------------------
        $nombre = $datos['nombre_propietario'];
        if ($nombre === '') {
            $errores['nombre_propietario'] = 'El nombre completo del propietario es obligatorio.';
        } elseif (mb_strlen($nombre) < 5 || mb_strlen($nombre) > 150) {
            $errores['nombre_propietario'] = 'El nombre debe tener entre 5 y 150 caracteres.';
        } elseif (!preg_match("/^[\\p{L}\\p{M} .'\\-]+$/u", $nombre)) {
            $errores['nombre_propietario'] = 'El nombre solo puede contener letras, espacios, apóstrofos, puntos y guiones.';
        } elseif (count(preg_split('/\s+/u', $nombre, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 2) {
            $errores['nombre_propietario'] = 'Ingrese el nombre completo (nombre y apellidos).';
        }

        // ---------------------------------------------------------------------
        // Correo
        // ---------------------------------------------------------------------
        $correo = mb_strtolower($datos['correo_contacto'], 'UTF-8');
        $datos['correo_contacto'] = $correo;

        if ($correo === '') {
            $errores['correo_contacto'] = 'El correo electrónico es obligatorio.';
        } elseif (mb_strlen($correo) > 190) {
            $errores['correo_contacto'] = 'El correo electrónico no puede superar los 190 caracteres.';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL) || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/u', $correo)) {
            $errores['correo_contacto'] = 'Ingrese un correo electrónico válido (ejemplo: nombre@dominio.com).';
        }

        return ['datos' => $datos, 'errores' => $errores];
    }

    /**
     * Convierte un valor de entrada en texto limpio: elimina etiquetas, caracteres
     * de control y espacios repetidos. Los saltos de línea no son válidos en
     * ninguno de los campos del formulario.
     */
    private static function text(mixed $value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        $value = (string) $value;
        $value = str_replace(["\r", "\n", "\t", "\0"], ' ', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }
}
