<?php

class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $cfg = require __DIR__ . '/config.php';
            $db = $cfg['db'];
            $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            self::ensureSchema(self::$pdo);
        }
        return self::$pdo;
    }

    /**
     * Tự tạo bảng nếu chưa có - tiện cho hosting, không cần import schema.sql tay.
     */
    private static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                v2_order_id INT NOT NULL UNIQUE,
                smm_order_id VARCHAR(64) NOT NULL,
                service_id INT NOT NULL,
                link VARCHAR(255) NOT NULL,
                quantity INT NOT NULL,
                time_leave INT NOT NULL,
                charge DECIMAL(10,4) NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT 'USD',
                status VARCHAR(20) NOT NULL DEFAULT 'Pending',
                start_count INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_smm_order (smm_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
