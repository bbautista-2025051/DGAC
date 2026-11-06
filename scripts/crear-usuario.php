<?php

declare(strict_types=1);

/**
 * DGAC · Registro de Drones
 * Crea un usuario interno (`admin` o `consulta`) en `usuarios_dgac`.
 *
 * Uso:
 *   php scripts/crear-usuario.php --nombre="María..." --correo=admin@dgac.gob.gt --rol=admin
 *   php scripts/crear-usuario.php --nombre="..." --correo=consulta@dgac.gob.gt --rol=consulta --password='Clave-Segura-123'
 *
 * Si se omite --password, se genera una contraseña temporal y se muestra una vez en la consola.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/api/bootstrap.php';

use Dgac\Drones\Database;

const USO = "Uso: php scripts/crear-usuario.php --nombre=\"Nombre completo\" --correo=correo@dominio.gob.gt --rol=admin|consulta [--password=\"Clave-Segura\"]\n";

function fail_cli(string $mensaje): never
{
    fwrite(STDERR, "Error: {$mensaje}\n\n" . USO);
    exit(1);
}

function argumentos(): array
{
    global $argv;
    $opciones = [];

    foreach (array_slice($argv, 1) as $argumento) {
        if (!preg_match('/^--([a-z_-]+)=(.+)$/u', $argumento, $coincidencia)) {
            fail_cli("Argumento inválido: {$argumento}. Use --clave=valor.");
        }
        $opciones[strtolower($coincidencia[1])] = trim($coincidencia[2]);
    }

    return $opciones;
}

function limpiar_texto(mixed $value): string
{
    $value = (string) $value;
    $value = str_replace(["\r", "\n", "\t", "\0"], ' ', $value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    return trim($value);
}

function generar_password(int $longitud = 16): string
{
    $grupos = [
        'abcdefghijkmnopqrstuvwxyz',
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        '23456789',
        '!@#%+=?_-',
    ];

    $caracteres = '';
    foreach ($grupos as $grupo) {
        $caracteres .= $grupo;
    }

    $password = '';
    foreach ($grupos as $grupo) {
        $password .= $grupo[random_int(0, strlen($grupo) - 1)];
    }

    while (strlen($password) < $longitud) {
        $password .= $caracteres[random_int(0, strlen($caracteres) - 1)];
    }

    return str_shuffle($password);
}

$opciones = argumentos();
$nombre = limpiar_texto($opciones['nombre'] ?? '');
$correo = mb_strtolower(limpiar_texto($opciones['correo'] ?? ''), 'UTF-8');
$rol = strtolower(limpiar_texto($opciones['rol'] ?? ''));
$password = (string) ($opciones['password'] ?? '');
$generada = false;

if ($nombre === '') {
    fail_cli('El nombre es obligatorio.');
}

if (mb_strlen($nombre) < 5 || mb_strlen($nombre) > 150) {
    fail_cli('El nombre debe tener entre 5 y 150 caracteres.');
}

if (!preg_match("/^[\\p{L}\\p{M} .'\\-]+$/u", $nombre)) {
    fail_cli('El nombre solo puede contener letras, espacios, apóstrofos, puntos y guiones.');
}

if ($correo === '' || mb_strlen($correo) > 190 ||
    !filter_var($correo, FILTER_VALIDATE_EMAIL) ||
    !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/u', $correo)) {
    fail_cli('El correo electrónico no es válido.');
}

if (!in_array($rol, ['admin', 'consulta'], true)) {
    fail_cli('El rol debe ser admin o consulta.');
}

if ($password === '') {
    $password = generar_password(16);
    $generada = true;
} else {
    if (strlen($password) < 12) {
        fail_cli('La contraseña debe tener al menos 12 caracteres.');
    }

    $clases = 0;
    $clases += preg_match('/[a-z]/', $password) ? 1 : 0;
    $clases += preg_match('/[A-Z]/', $password) ? 1 : 0;
    $clases += preg_match('/[0-9]/', $password) ? 1 : 0;
    $clases += preg_match('/[^a-zA-Z0-9]/', $password) ? 1 : 0;

    if ($clases < 3) {
        fail_cli('La contraseña debe combinar al menos tres de: minúsculas, mayúsculas, números o símbolos.');
    }
}

try {
    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'INSERT INTO usuarios_dgac (nombre, correo, password_hash, rol, activo, intentos_fallidos)
         VALUES (:nombre, :correo, :hash, :rol, 1, 0)'
    );
    $stmt->execute([
        'nombre' => $nombre,
        'correo' => $correo,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'rol' => $rol,
    ]);

    $id = (int) $pdo->lastInsertId();

    fwrite(STDOUT, "Usuario creado correctamente.\n");
    fwrite(STDOUT, "ID: {$id}\n");
    fwrite(STDOUT, "Nombre: {$nombre}\n");
    fwrite(STDOUT, "Correo: {$correo}\n");
    fwrite(STDOUT, "Rol: {$rol}\n");

    if ($generada) {
        fwrite(STDOUT, "Contraseña temporal: {$password}\n");
        fwrite(STDOUT, "Guárdela en un gestor seguro y cámbiela al primer acceso.\n");
    }

    fwrite(STDOUT, "Nota: no se muestran contraseñas existentes ni se crean usuarios sin autorización.\n");
    exit(0);
} catch (PDOException $e) {
    if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
        fail_cli('Ya existe un usuario con ese correo.');
    }

    fwrite(STDERR, "Error: no se pudo crear el usuario. Revise la conexión a la base de datos.\n");
    exit(1);
}
