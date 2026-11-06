<?php

declare(strict_types=1);

namespace Dgac\Drones;

use PDO;
use Throwable;

/**
 * Autenticación y autorización interna.
 *
 * Public endpoints only require a CSRF token and rate limiting. Internal list,
 * search and PDF downloads also require a valid session with a role that may
 * access the internal records. Only `admin` may download comprobantes.
 */
final class Auth
{
    public const SESSION_USER_KEY = 'dgac_usuario_id';
    public const SESSION_USER_CACHE = 'dgac_usuario_snapshot';

    /** Hash dummy para equiparar el coste criptográfico cuando el correo no existe. */
    private const DUMMY_HASH = '$2y$10$n4.BOHlCj7w1w1oR8ZX4heXhzDlBl8aM3TZpZFQA6bD6mlIjj6cme';

    /** @var array<string, mixed>|null */
    private static ?array $currentUser = null;
    private static bool $currentUserLoaded = false;

    /**
     * Devuelve el usuario interno autenticado o null.
     *
     * La consulta descarta usuarios inactivos y actualiza el caché estático. Si
     * el usuario se desactivó, la sesión se destruye para no seguir tratándolo
     * como válido.
     *
     * @return array<string, mixed>|null
     */
    public static function currentUser(): ?array
    {
        if (self::$currentUserLoaded) {
            return self::$currentUser;
        }

        start_session();

        $id = $_SESSION[self::SESSION_USER_KEY] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            self::$currentUserLoaded = true;
            self::$currentUser = null;

            return null;
        }

        $id = (int) $id;
        $stmt = Database::connection()->prepare(
            'SELECT id, nombre, correo, rol, activo
               FROM usuarios_dgac
              WHERE id = :id AND activo = 1
              LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            unset($_SESSION[self::SESSION_USER_KEY], $_SESSION[self::SESSION_USER_CACHE]);
            session_regenerate_id(true);
            self::$currentUserLoaded = true;
            self::$currentUser = null;

            return null;
        }

        self::$currentUser = self::publicUser($row);
        self::$currentUserLoaded = true;

        return self::$currentUser;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function authenticatedUserForResponse(): ?array
    {
        return self::currentUser();
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireUser(): array
    {
        $user = self::currentUser();

        if ($user === null) {
            fail(401, 'Debe iniciar sesión con un usuario autorizado.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireDownloadUser(): array
    {
        $user = self::requireUser();

        if (empty($user['puede_descargar'])) {
            fail(403, 'Su usuario no tiene permiso para descargar comprobantes.');
        }

        return $user;
    }

    public static function canCurrentUserDownload(): bool
    {
        $user = self::currentUser();

        return is_array($user) && !empty($user['puede_descargar']);
    }

    /**
     * Inicia sesión con correo y contraseña.
     *
     * Usa hash dummy cuando el correo no existe para no revelar si el usuario
     * está registrado. Bloquea temporalmente el acceso después de varios
     * intentos fallidos.
     *
     * @return array<string, mixed>
     */
    public static function login(string $correo, string $password): array
    {
        $correo = mb_strtolower(trim($correo), 'UTF-8');
        $password = (string) $password;

        if ($correo === '' || mb_strlen($correo) > 190 ||
            !filter_var($correo, FILTER_VALIDATE_EMAIL) ||
            !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/u', $correo)) {
            fail(422, 'Ingrese un correo electrónico válido.');
        }

        if ($password === '' || mb_strlen($password) > 4096) {
            fail(422, 'Ingrese la contraseña del usuario.');
        }

        enforce_rate_limit('login', env_int('AUTH_LOGIN_RATE_MAX', 10, 1, 100), env_int('AUTH_LOGIN_RATE_WINDOW', 900, 60, 86400));

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT id, nombre, correo, password_hash, rol, activo, intentos_fallidos, bloqueado_hasta
               FROM usuarios_dgac
              WHERE correo = :correo
              LIMIT 1'
        );
        $stmt->execute(['correo' => $correo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || (int) $row['activo'] !== 1) {
            password_verify($password, self::DUMMY_HASH);
            log_event('warning', 'Intento de inicio de sesión con correo no habilitado.', [
                'ip' => client_ip(),
                'correo_mascarado' => mask_email($correo),
            ]);
            fail(401, 'Correo o contraseña incorrectos.');
        }

        $lockedUntil = self::lockedUntil($row['bloqueado_hasta'] ?? null);
        if ($lockedUntil !== null) {
            header('Retry-After: ' . max(1, $lockedUntil->getTimestamp() - time()));
            fail(429, 'El acceso de este usuario está bloqueado temporalmente por intentos fallidos. Intente más adelante.');
        }

        if (!password_verify($password, (string) $row['password_hash'])) {
            self::registerFailedLogin($pdo, (int) $row['id'], (int) $row['intentos_fallidos']);
            log_event('warning', 'Inicio de sesión rechazado por contraseña incorrecta.', [
                'id' => (int) $row['id'],
                'ip' => client_ip(),
            ]);
            fail(401, 'Correo o contraseña incorrectos.');
        }

        $stmt = $pdo->prepare(
            'UPDATE usuarios_dgac
                SET intentos_fallidos = 0,
                    bloqueado_hasta = NULL,
                    ultimo_acceso = :acceso
              WHERE id = :id'
        );
        $stmt->execute([
            'acceso' => date('Y-m-d H:i:s'),
            'id' => (int) $row['id'],
        ]);

        start_session();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_KEY] = (int) $row['id'];
        self::$currentUserLoaded = false;
        self::$currentUser = null;

        $user = self::currentUser();

        log_event('info', 'Inicio de sesión exitoso.', [
            'id' => (int) $row['id'],
            'rol' => (string) $row['rol'],
            'ip' => client_ip(),
        ]);

        return $user ?? self::publicUser($row);
    }

    /**
     * Destruye la sesión del usuario interno y deja la sesión CSRF lista para
     * un nuevo acceso.
     */
    public static function logout(): void
    {
        start_session();
        $userId = $_SESSION[self::SESSION_USER_KEY] ?? null;

        unset($_SESSION[self::SESSION_USER_KEY], $_SESSION[self::SESSION_USER_CACHE]);
        session_regenerate_id(true);
        self::$currentUserLoaded = false;
        self::$currentUser = null;

        if ($userId !== null) {
            log_event('info', 'Cierre de sesión de usuario interno.', [
                'id' => is_numeric($userId) ? (int) $userId : 0,
                'ip' => client_ip(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function publicUser(array $row): array
    {
        $rol = (string) ($row['rol'] ?? 'consulta');

        return [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'correo' => (string) $row['correo'],
            'rol' => $rol,
            'rol_label' => $rol === 'admin' ? 'Administrador' : 'Consulta',
            'puede_descargar' => $rol === 'admin',
            'puede_consultar' => in_array($rol, ['admin', 'consulta'], true),
        ];
    }

    private static function lockedUntil(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $locked = new \DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }

        return $locked > new \DateTimeImmutable('now') ? $locked : null;
    }

    private static function registerFailedLogin(PDO $pdo, int $id, int $currentAttempts): void
    {
        $attempts = max(0, $currentAttempts) + 1;
        $max = env_int('AUTH_LOCKOUT_ATTEMPTS', 5, 1, 20);
        $minutes = env_int('AUTH_LOCKOUT_MINUTES', 15, 1, 1440);
        $locked = $attempts >= $max ? date('Y-m-d H:i:s', time() + ($minutes * 60)) : null;

        $stmt = $pdo->prepare(
            'UPDATE usuarios_dgac
                SET intentos_fallidos = :intentos,
                    bloqueado_hasta = :bloqueado
              WHERE id = :id'
        );
        $stmt->execute([
            'intentos' => min(255, $attempts),
            'bloqueado' => $locked,
            'id' => $id,
        ]);
    }
}
