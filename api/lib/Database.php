<?php

declare(strict_types=1);

namespace Dgac\Drones;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexión PDO a la base de datos existente (MariaDB/MySQL).
 * No crea bases de datos ni tablas: la estructura se instala con sql/001.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = env_value('DB_HOST', '127.0.0.1');
        $port = env_int('DB_PORT', 3306, 1, 65535);
        $database = env_value('DB_DATABASE', '');
        $username = env_value('DB_USERNAME', '');
        $password = env_value('DB_PASSWORD', '');
        $charset = env_value('DB_CHARSET', 'utf8mb4');

        if ($database === null || $database === '') {
            throw new RuntimeException('Falta DB_DATABASE en la configuración.');
        }
        if ($username === null || $username === '') {
            throw new RuntimeException('Falta DB_USERNAME en la configuración.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        $sslCa = env_value('DB_SSL_CA');
        if ($sslCa !== null && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        }

        try {
            self::$pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            log_event('error', 'No se pudo conectar a la base de datos.', [
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No fue posible conectar con la base de datos.', 0, $e);
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
