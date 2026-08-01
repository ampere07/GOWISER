<?php
class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $host    = $_ENV['DB_HOST']    ?? 'localhost';
            $port    = $_ENV['DB_PORT']    ?? '3306';
            $dbname  = $_ENV['DB_NAME']    ?? 'netmanager';
            $user    = $_ENV['DB_USER']    ?? 'root';
            $pass    = $_ENV['DB_PASS']    ?? '';
            $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
                ]);
            } catch (PDOException $e) {
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    error_log('DB Error: ' . $e->getMessage());
                }
                die('A database error occurred. Please contact the administrator.');
            }
        }
        return self::$instance;
    }
}

function db(): PDO {
    return Database::getInstance();
}

function tableExists(string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $dbName = $_ENV['DB_NAME'] ?? 'netmanager';
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $stmt->execute([$dbName, $table]);
    return $cache[$table] = (bool)$stmt->fetchColumn();
}
