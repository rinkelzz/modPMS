<?php

namespace ModPMS;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $configPath = __DIR__ . '/../config/database.php';

        if (!is_readable($configPath)) {
            throw new RuntimeException('Keine Datenbankkonfiguration gefunden. Bitte führen Sie den Installer aus.');
        }

        $config = include $configPath;

        if (!is_array($config)) {
            throw new RuntimeException('Die Datenbankkonfiguration ist ungültig.');
        }

        $driver = $config['driver'] ?? 'mysql';

        if ($driver !== 'mysql') {
            throw new RuntimeException(sprintf('Der Treiber "%s" wird derzeit nicht unterstützt.', (string) $driver));
        }

        $host = $config['host'] ?? null;
        $database = $config['database'] ?? null;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';
        $port = $config['port'] ?? '';

        if ($host === null || $database === null || $username === null) {
            throw new RuntimeException('Die Datenbankkonfiguration ist unvollständig.');
        }

        $dsn = sprintf(
            'mysql:host=%s;%sdbname=%s;charset=%s',
            $host,
            $port !== '' ? sprintf('port=%s;', $port) : '',
            $database,
            $charset
        );

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Verbindung zur Datenbank fehlgeschlagen: ' . $exception->getMessage(), 0, $exception);
        }

        self::$connection = $pdo;

        return self::$connection;
    }
}
